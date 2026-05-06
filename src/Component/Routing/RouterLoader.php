<?php

declare(strict_types=1);

namespace Strux\Component\Routing;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Strux\Component\Routing\Attributes\ApiController;
use Strux\Component\Routing\Attributes\ApiRoute;
use Strux\Component\Cache\Attributes\Cache;
use Strux\Component\Http\Attributes\Consumes;
use Strux\Component\Middleware\Attributes\Middleware as MiddlewareAttribute;
use Strux\Component\Routing\Attributes\Prefix as PrefixAttribute;
use Strux\Component\Http\Attributes\Produces;
use Strux\Component\Http\Attributes\ResponseHeader;
use Strux\Component\Http\Attributes\ResponseStatus;
use Strux\Component\Routing\Attributes\Route as WebRoute;

readonly class RouterLoader
{
    public function __construct(
        private Router $router,
        private ContainerInterface $container,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Scans a directory for controllers and registers them.
     * @throws ReflectionException
     */
    public function loadFromDirectory(string $directory, bool $isApi): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $classes = $this->findClassNames($directory);
        $this->processControllers($classes, $isApi);
    }

    /**
     * Generic controller processor.
     * @throws ReflectionException
     */
    private function processControllers(array $controllers, bool $isApi): void
    {
        $context = $isApi ? 'API Controller' : 'Controller';
        foreach ($controllers as $controllerClass) {
            if (!class_exists($controllerClass)) {
                $this->logger->warning("RouterLoader: $context class '$controllerClass' not found. Skipping.");
                continue;
            }
            $this->registerRoutesForController($controllerClass, $isApi);
        }
    }

    /**
     * Helper to recursively find PHP classes in a directory based on PSR-4 conventions.
     * Assumes 'src' maps to 'Application'.
     */
    private function findClassNames(string $directory): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getRealPath();

                // We need to determine the Namespace from the file path.
                // We assume the project root has 'src' which maps to 'Application'.

                // 1. Find where 'src' starts
                $srcPos = strpos($filePath, 'src' . DIRECTORY_SEPARATOR);

                if ($srcPos !== false) {
                    // 2. Extract relative path: src/Http/Controllers/Api/Test.php
                    // +4 length of "src" + separator
                    $relativePath = substr($filePath, $srcPos + 4);

                    // 3. Remove extension
                    $relativePath = str_replace('.php', '', $relativePath);

                    // 4. Convert slashes to namespace backslashes
                    $classPath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

                    // 5. Prepend Application base namespace
                    $fqcn = "App\\" . $classPath;

                    $classes[] = $fqcn;
                }
            }
        }
        return $classes;
    }

    /**
     * Registers all routes for a given controller class, differentiating between standard and API routes.
     * @throws ReflectionException
     */
    private function registerRoutesForController(string $controllerClass, bool $isApi): void
    {
        $reflectionClass = new ReflectionClass($controllerClass);

        if ($reflectionClass->isAbstract() || !$reflectionClass->isInstantiable()) {
            return;
        }

        // API controllers must have the #[Controller] or #[ApiController] attribute depending on your setup
        // Strict check:
        if ($isApi && empty($reflectionClass->getAttributes(ApiController::class))) {
            return;
        }

        $classPrefixAttributes = $reflectionClass->getAttributes(PrefixAttribute::class);
        $basePrefixes = [];
        if (!empty($classPrefixAttributes)) {
            foreach ($classPrefixAttributes as $attribute) {
                $prefixInstance = $attribute->newInstance();
                $basePrefixes[] = ['path' => $prefixInstance->path, 'defaults' => $prefixInstance->defaults];
            }
        } else {
            $basePrefixes[] = ['path' => '', 'defaults' => []];
        }

        $classMiddleware = $this->getMiddleware($reflectionClass);
        $classConsumes = $isApi ? $this->getAttributeInstance($reflectionClass, Consumes::class) : null;
        $classProduces = $isApi ? $this->getAttributeInstance($reflectionClass, Produces::class) : null;

        $routeAttributeClass = $isApi ? ApiRoute::class : WebRoute::class;

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $controllerClass) {
                continue;
            }

            $routeAttributes = $method->getAttributes($routeAttributeClass, ReflectionAttribute::IS_INSTANCEOF);
            if (empty($routeAttributes)) {
                continue;
            }

            $methodMiddleware = $this->getMiddleware($method);

            /** @var ?Cache $cache */
            $cache = $this->getAttributeInstance($method, Cache::class);
            $cacheTtl = $cache?->ttl;

            foreach ($routeAttributes as $routeAttribute) {
                $route = $routeAttribute->newInstance();

                foreach ($basePrefixes as $basePrefix) {
                    $fullUri = '/' . trim(str_replace('//', '/', $basePrefix['path'] . '/' . $route->path), '/');
                    if ($fullUri === '')
                        $fullUri = '/';

                    if (empty($route->methods)) {
                        error_log("RouterLoader: No HTTP methods for route '{$route->path}' in {$controllerClass}::{$method->getName()}");
                        continue;
                    }

                    $finalMiddleware = array_unique(array_merge($classMiddleware, $methodMiddleware, $route->middleware));
                    $finalDefaults = array_merge($basePrefix['defaults'], $route->defaults);

                    // --- Divergent Logic: API vs Regular Route ---
                    if ($isApi) {
                        /** @var ApiRoute $route */
                        $methodConsumes = $this->getAttributeInstance($method, Consumes::class);
                        $methodProduces = $this->getAttributeInstance($method, Produces::class);
                        $responseStatus = $this->getAttributeInstance($method, ResponseStatus::class);

                        $responseHeaders = array_map(
                            fn($attr) => $attr->newInstance(),
                            $method->getAttributes(ResponseHeader::class, ReflectionAttribute::IS_INSTANCEOF)
                        );

                        $this->router->addRouteDefinition($route->methods, $fullUri, [$controllerClass, $method->getName()])
                            ->middleware($finalMiddleware)
                            ->defaults($finalDefaults)
                            ->name($route->name ?? '')
                            ->setExtra([
                                'consumes' => $methodConsumes ?? $classConsumes,
                                'produces' => $methodProduces ?? $classProduces,
                                'status' => $responseStatus?->code,
                                'headers' => $responseHeaders,
                                'cache_ttl' => $cacheTtl
                            ]);
                    } else {
                        /** @var WebRoute $route */
                        if ($route->toPath || $route->toRoute || $route->toAction) {
                            $target = $route->toPath ?? $route->toRoute;
                            $action = is_string($route->toAction) ? explode('::', $route->toAction) : $route->toAction;
                            $this->router->addRedirect($fullUri, $target, 302, $route->toRoute ? $target : null, $action);
                            continue;
                        }

                        $this->router->addRouteDefinition($route->methods, $fullUri, [$controllerClass, $method->getName()])
                            ->middleware($finalMiddleware)
                            ->defaults($finalDefaults)
                            ->name($route->name ?? '')
                            ->setExtra(['cache_ttl' => $cacheTtl]);
                    }
                }
            }
        }
    }

    /**
     * Gets an attribute instance from a reflection object.
     */
    private function getAttributeInstance(ReflectionClass|ReflectionMethod $reflection, string $attributeClass): ?object
    {
        $attributes = $reflection->getAttributes($attributeClass);
        return $attributes ? $attributes[0]->newInstance() : null;
    }

    /**
     * Gets all middleware from a reflection object (class or method).
     */
    private function getMiddleware(ReflectionClass|ReflectionMethod $reflection): array
    {
        $middleware = [];
        $attributes = $reflection->getAttributes(MiddlewareAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $middleware = array_merge($middleware, $instance->middlewareClasses);
        }
        return array_unique($middleware);
    }
}