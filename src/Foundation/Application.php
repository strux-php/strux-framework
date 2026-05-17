<?php

declare(strict_types=1);

namespace Strux\Foundation;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Strux\Bootstrapping\Registry\ServiceRegistry;
use Strux\Component\Config\Config;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\Http\Middleware\Dispatcher as MiddlewareDispatcher;
use Strux\Component\Http\Psr7\ServerRequestCreator;
use Strux\Component\Http\ResponseEmitter;
use Strux\Component\Routing\RouteDispatcher;
use Strux\Component\Routing\Router;
use Throwable;

class Application
{
    private ContainerInterface $container;
    private string $rootPath;

    /**
     * @var array<int, string|object>
     */
    private array $globalMiddleware = [];

    public function __construct(ContainerInterface $container, string $rootPath = '')
    {
        $this->container = $container;
        $this->rootPath = $rootPath;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getRouter(): Router
    {
        return $this->container->get(Router::class);
    }

    /**
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @return LoggerInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class);
    }

    /**
     * Get a directory path by key from the DirectoryResolver.
     *
     * @param string $key The directory key (e.g., 'controllers', 'views', 'cache')
     * @return string The absolute path
     */
    public function getDirectory(string $key): string
    {
        if ($this->container->has(DirectoryInterface::class)) {
            return $this->container->get(DirectoryInterface::class)->get($key);
        }

        throw new RuntimeException('DirectoryInterface service not found in container.');
    }

    /**
     * Get the log directory.
     */
    public function getLogDir(): string
    {
        if ($this->container->has(\Strux\Component\Config\DirectoryInterface::class)) {
            return $this->container->get(\Strux\Component\Config\DirectoryInterface::class)->get('logs');
        }

        if ($this->container->has(\Strux\Component\Config\Config::class)) {
            $default = \Strux\Component\Config\DirectoryResolver::getDefaults($this->rootPath)['logs'];
            return $this->container->get(\Strux\Component\Config\Config::class)->get('app.log_dir', $default);
        }

        return \Strux\Component\Config\DirectoryResolver::getDefaults($this->rootPath)['logs'];
    }

    /**
     * Get the cache directory.
     */
    public function getCacheDir(): string
    {
        if ($this->container->has(\Strux\Component\Config\DirectoryInterface::class)) {
            return $this->container->get(\Strux\Component\Config\DirectoryInterface::class)->get('cache');
        }

        if ($this->container->has(\Strux\Component\Config\Config::class)) {
            $default = \Strux\Component\Config\DirectoryResolver::getDefaults($this->rootPath)['cache'];
            return $this->container->get(\Strux\Component\Config\Config::class)->get('app.cache_dir', $default);
        }

        return \Strux\Component\Config\DirectoryResolver::getDefaults($this->rootPath)['cache'];
    }

    /**
     * Get the view/templates directory.
     */
    public function getViewDir(): string
    {
        if ($this->container->has(\Strux\Component\Config\DirectoryInterface::class)) {
            return $this->container->get(\Strux\Component\Config\DirectoryInterface::class)->get('views');
        }

        if ($this->container->has(\Strux\Component\Config\Config::class)) {
            $default = \Strux\Component\Config\DirectoryResolver::getDefaults($this->rootPath)['views'];
            return $this->container->get(\Strux\Component\Config\Config::class)->get('app.view_dir', $default);
        }

        return \Strux\Component\Config\DirectoryResolver::getDefaults($this->rootPath)['views'];
    }

    /**
     * Get the root path of the application.
     *
     * @return string
     */
    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * Get the current application environment.
     *
     */
    public function getEnvironment(): string
    {
        if ($this->container->has(Config::class)) {
            return $this->container->get(Config::class)->get('app.env', 'production');
        }

        throw new RuntimeException('Config service not found in container.');
    }

    /**
     * Add a global middleware to the stack.
     *
     * @param object|string $middleware
     * @return self
     */
    public function addMiddleware(object|string $middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Override an existing middleware with a new one.
     * Useful for replacing framework defaults.
     *
     * @param string $targetMiddleware Class name of the middleware to replace
     * @param object|string $newMiddleware The new middleware
     * @return self
     */
    public function overrideMiddleware(string $targetMiddleware, object|string $newMiddleware): self
    {
        foreach ($this->globalMiddleware as $key => $middleware) {
            if ($middleware === $targetMiddleware || (is_object($middleware) && get_class($middleware) === $targetMiddleware)) {
                $this->globalMiddleware[$key] = $newMiddleware;
            }
        }
        return $this;
    }

    /**
     * Disable a middleware from the global stack.
     *
     * @param string $targetMiddleware Class name of the middleware to disable
     * @return self
     */
    public function disableMiddleware(string $targetMiddleware): self
    {
        foreach ($this->globalMiddleware as $key => $middleware) {
            if ($middleware === $targetMiddleware || (is_object($middleware) && get_class($middleware) === $targetMiddleware)) {
                unset($this->globalMiddleware[$key]);
            }
        }
        // Reindex array
        $this->globalMiddleware = array_values($this->globalMiddleware);
        return $this;
    }

    /**
     * Get the list of global middleware.
     *
     * @return array<int, string|object>
     */
    public function getMiddlewareStack(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * Register a Service Registry.
     *
     * @param string $registryClass
     * @return self
     */
    public function addRegistry(string $registryClass): self
    {
        /** @var ServiceRegistry $registry */
        $registry = new $registryClass($this->container);

        if (method_exists($registry, 'build')) {
            $registry->build();
        } elseif (method_exists($registry, 'register')) {
            $registry->register();
        } else {
            throw new RuntimeException("ServiceRegistry class {$registryClass} must implement a build() or register() method.");
        }

        return $this;
    }

    /**
     * Check if the application is in development environment.
     */
    public function isDevelopment(): bool
    {
        return $this->getEnvironment() === 'development';
    }

    /**
     * Check if the application is in production environment.
     */
    public function isProduction(): bool
    {
        return $this->getEnvironment() === 'production';
    }

    /**
     * Check if the application is in testing environment.
     */
    public function isTesting(): bool
    {
        return $this->getEnvironment() === 'testing';
    }

    /**
     * Register a Singleton binding in the container.
     *
     * @param string $abstract
     * @param mixed|null $concrete
     * @return self
     */
    public function addSingleton(string $abstract, mixed $concrete = null): self
    {
        if (method_exists($this->container, 'singleton')) {
            $this->container->singleton($abstract, $concrete);
        }
        return $this;
    }

    /**
     * Register a Transient binding in the container.
     *
     * @param string $abstract
     * @param mixed|null $concrete
     * @return self
     */
    public function addTransient(string $abstract, mixed $concrete = null): self
    {
        if (method_exists($this->container, 'bind')) {
            $this->container->bind($abstract, $concrete);
        }
        return $this;
    }

    /**
     * Register a GET route.
     *
     * @param string $uri
     * @param callable|array $action
     * @return Router
     */
    public function get(string $uri, callable|array $action): Router
    {
        try {
            return $this->getRouter()->get($uri, $action);
        } catch (Throwable $e) {
            throw new RuntimeException('Router service not found in container.', 0, $e);
        }
    }

    /**
     * Register a POST route.
     *
     * @param string $uri
     * @param callable|array $action
     * @return Router
     */
    public function post(string $uri, callable|array $action): Router
    {
        try {
            return $this->getRouter()->post($uri, $action);
        } catch (Throwable $e) {
            throw new RuntimeException('Router service not found in container.', 0, $e);
        }
    }

    /**
     * Register a PUT route.
     *
     * @param string $uri
     * @param callable|array $action
     * @return Router
     */
    public function put(string $uri, callable|array $action): Router
    {
        try {
            return $this->getRouter()->put($uri, $action);
        } catch (Throwable $e) {
            throw new RuntimeException('Router service not found in container.', 0, $e);
        }
    }

    /**
     * Register a PATCH route.
     *
     * @param string $uri
     * @param callable|array $action
     * @return Router
     */
    public function patch(string $uri, callable|array $action): Router
    {
        try {
            return $this->getRouter()->patch($uri, $action);
        } catch (Throwable $e) {
            throw new RuntimeException('Router service not found in container.', 0, $e);
        }
    }

    /**
     * Register a DELETE route.
     *
     * @param string $uri
     * @param callable|array $action
     * @return Router
     */
    public function delete(string $uri, callable|array $action): Router
    {
        try {
            return $this->getRouter()->delete($uri, $action);
        } catch (Throwable $e) {
            throw new RuntimeException('Router service not found in container.', 0, $e);
        }
    }

    /**
     * Register a Route Group.
     *
     * @param array $attributes
     * @param callable $callback
     * @return self
     */
    public function group(array $attributes, callable $callback): self
    {
        try {
            $this->getRouter()->group($attributes, function () use ($callback) {
                $callback($this);
            });
        } catch (Throwable $e) {
            throw new RuntimeException('Router service not found in container.', 0, $e);
        }

        return $this;
    }

    /**
     * Runs the application, handles the request, and emits the response.
     */
    public function run(): void
    {
        /** @var ServerRequestCreator $requestCreator */
        $requestCreator = $this->container->get(ServerRequestCreator::class);
        $request = $requestCreator->fromGlobals();

        /** @var RouteDispatcher $routeDispatcher */
        $routeDispatcher = $this->container->get(RouteDispatcher::class);

        $middlewareDispatcher = new MiddlewareDispatcher($this->globalMiddleware, $this->container);

        $response = $middlewareDispatcher->dispatch($request, $routeDispatcher);

        $responseEmitter = new ResponseEmitter();
        $responseEmitter->emit($response);
    }
}