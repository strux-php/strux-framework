<?php

declare(strict_types=1);

namespace Strux\Auth\Middleware;

use InvalidArgumentException;
use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use Strux\Auth\AuthManager;
use Strux\Auth\Attributes\Authorize;
use Strux\Auth\Attributes\Policy;
use Strux\Auth\Entity\User;
use Strux\Component\Config\Config;
use Strux\Component\Database\ORM\Model;
use Strux\Component\Exceptions\Http\AccessDeniedHttpException;
use Strux\Component\Exceptions\Http\NotFoundHttpException;
use Strux\Component\Exceptions\Http\ServerErrorHttpException;
use Strux\Component\Routing\Attributes\RouteEntity;
use Strux\Component\Routing\Router;
use Strux\Support\ContainerBridge;
use Strux\Support\Helpers\FlashInterface;

class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthManager                 $authManager,
        private ResponseFactoryInterface    $responseFactory,
        private Router                      $router,
        private FlashInterface              $flash,
        private ContainerInterface          $container,
        private ?LoggerInterface            $logger = null,
        private ?string                     $loginRouteName = null,
        private ?string                     $nextParameter = null
    ) {}

    /**
     * @throws JsonException
     * @throws ReflectionException
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $sentinel = $this->authManager->sentinel('web');
        $user = $sentinel->user();

        if ($user) {
            $request = $request->withAttribute('user', $user);

            $this->logger?->info("[AuthorizationMiddleware] User with ID {$sentinel->id()} is authenticated. Proceeding.");

            $routeInfo = $request->getAttribute('route');

            $controller = $routeInfo['controller'] ?? null;
            $method = $routeInfo['method'] ?? null;

            if ($controller && $method) {
                $reflectionClass = new ReflectionClass($controller);
                $this->checkAuthorization($reflectionClass, $user, $request);

                if ($reflectionClass->hasMethod($method)) {
                    $this->checkAuthorization($reflectionClass->getMethod($method), $user, $request);
                }
            }
            return $handler->handle($request);
        }

        $this->logger?->info("[AuthorizationMiddleware] User is not authenticated. Redirecting to login.");

        if ($request->getHeaderLine('Accept') === 'application/json') {
            $this->logger?->info("[AuthorizationMiddleware] Returning 401 Unauthorized for JSON request.");
            $response = $this->responseFactory->createResponse(401);
            $response->getBody()->write(json_encode([
                'error' => [
                    'code' => 401,
                    'type' => 'unauthorized',
                    'message' => 'You must be logged in to access this resource.'
                ]
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $this->flash->set('error', 'You must be logged in to access this page.');

        /** @var Config $config */
        $config = ContainerBridge::resolve(Config::class);

        $this->loginRouteName ??= $config->get('auth.defaults.login_route', 'login');

        $this->nextParameter ??= $config->get('auth.defaults.next_parameter', 'next');

        try {
            $currentPath = (!empty($request->getUri()->getPath()) && $request->getUri()->getPath() !== '/')
                ? $request->getUri()->getPath()
                : '';

            if (str_starts_with($this->loginRouteName, '/')) {
                if (!empty($currentPath)) {
                    $loginUrl = "{$this->loginRouteName}?" . http_build_query([
                        $this->nextParameter => $currentPath
                    ]);
                } else {
                    $loginUrl = $this->loginRouteName;
                }
            } else {
                if (!empty($currentPath)) {
                    $loginUrl = $this->router->route(
                        $this->loginRouteName,
                        [$this->nextParameter => $currentPath]
                    );
                } else {
                    $loginUrl = $this->router->route($this->loginRouteName);
                }
            }
        } catch (InvalidArgumentException $e) {
            $this->logger?->error(
                "[AuthorizationMiddleware] CRITICAL: Login route '$this->loginRouteName' not found or URL generation failed.",
                ['exception_message' => $e->getMessage()]
            );
            $loginUrl = '/login';
        }

        $this->logger?->info("[AuthorizationMiddleware] Redirecting to: $loginUrl");

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $loginUrl);
    }

    private function checkAuthorization(
        ReflectionClass|\ReflectionMethod $reflector,
        User                             $user,
        ServerRequestInterface           $request
    ): void {
        $attributes = $reflector->getAttributes(Authorize::class);

        foreach ($attributes as $attribute) {
            /** @var Authorize $authAttr */
            $authAttr = $attribute->newInstance();

            // Check Roles
            if (!empty($authAttr->roles)) {
                if (!$user->hasRole($authAttr->roles)) {
                    throw new AccessDeniedHttpException("User does not have the required role.");
                }
            }

            // Check Permissions
            if (!empty($authAttr->permissions)) {
                if (!$user->hasPermission($authAttr->permissions)) {
                    throw new AccessDeniedHttpException("User does not have the required permission.");
                }
            }

            // Check Authorities (policy-based authorization)
            if (!empty($authAttr->authorities)) {
                [$resourceClass, $policyClass] = $authAttr->authorities;

                $ability = $authAttr->ability ?? $this->deriveAbility($reflector, $request);

                $resource = $this->resolveResource($reflector, $resourceClass, $request->getAttributes());

                if ($resource === null) {
                    throw new NotFoundHttpException("Resource not found for authorization.");
                }

                $policyInstance = $this->container->get($policyClass);
                $method = 'can' . ucfirst($ability);

                if (!method_exists($policyInstance, $method)) {
                    throw new ServerErrorHttpException("Policy method '{$method}' not found.");
                }

                if (!$policyInstance->{$method}($user, $resource)) {
                    throw new AccessDeniedHttpException("User does not have the required authority.");
                }
            }
        }
    }

    private function deriveAbility(
        ReflectionClass|\ReflectionMethod $reflector,
        ServerRequestInterface            $request
    ): string {
        $httpMethod = strtoupper($request->getMethod());

        // If it's a method reflector, use the controller method name as hint
        if ($reflector instanceof \ReflectionMethod) {
            return match ($reflector->getName()) {
                'index', 'list'  => 'list',
                'show', 'view'   => 'view',
                'create', 'store' => 'create',
                'edit', 'update'  => 'update',
                'destroy', 'delete' => 'delete',
                default => match ($httpMethod) {
                    'GET'    => 'view',
                    'POST'   => 'create',
                    'PUT', 'PATCH' => 'update',
                    'DELETE' => 'delete',
                    default  => 'view',
                },
            };
        }

        // Class-level attribute — derive from HTTP method only
        return match ($httpMethod) {
            'GET'    => 'view',
            'POST'   => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default  => 'view',
        };
    }

    /**
     * Resolve the resource model from route parameters for policy checking.
     * Respects #[RouteEntity] mapping if present on the controller parameter.
     */
    private function resolveResource(
        ReflectionClass|\ReflectionMethod $reflector,
        string                            $resourceClass,
        array                             $routeParams
    ): ?object {
        // Find the controller method parameter typed with the resource class
        $param = $this->findResourceParameter($reflector, $resourceClass);

        if ($param === null) {
            // Fall back to :id
            $id = $routeParams['id'] ?? null;
            if ($id !== null && is_subclass_of($resourceClass, Model::class)) {
                return $resourceClass::find($id);
            }
            return null;
        }

        // Check for #[RouteEntity] attribute on the parameter
        $routeEntityAttr = $param->getAttributes(RouteEntity::class)[0] ?? null;

        if ($routeEntityAttr) {
            $instance = $routeEntityAttr->newInstance();
            $query = $resourceClass::query();

            foreach ($instance->mapping as $routeParam => $column) {
                $value = $routeParams[$routeParam] ?? null;
                if ($value === null) {
                    return null;
                }
                $query->where($column, $value);
            }

            if (!empty($instance->with)) {
                $query->with(...$instance->with);
            }

            return $query->first();
        }

        // Default: match by parameter name or :id
        $paramName = $param->getName();
        $routeKey = array_key_exists($paramName, $routeParams) ? $paramName : 'id';
        $id = $routeParams[$routeKey] ?? null;

        if ($id !== null && is_subclass_of($resourceClass, Model::class)) {
            return $resourceClass::find($id);
        }

        return null;
    }

    /**
     * Find the ReflectionParameter on the controller method that matches the resource class.
     */
    private function findResourceParameter(
        ReflectionClass|\ReflectionMethod $reflector,
        string                            $resourceClass
    ): ?ReflectionParameter {
        if (!($reflector instanceof \ReflectionMethod)) {
            return null;
        }

        foreach ($reflector->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === $resourceClass) {
                return $param;
            }
        }

        return null;
    }
}
