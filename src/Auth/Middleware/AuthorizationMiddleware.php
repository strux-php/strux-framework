<?php

declare(strict_types=1);

namespace Strux\Auth\Middleware;

use App\Domain\Identity\Entity\User;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Strux\Auth\Auth;
use Strux\Auth\AuthManager;
use Strux\Auth\Attributes\Authorize;
use Strux\Component\Config\Config;
use Strux\Component\Exceptions\AuthorizationException;
use Strux\Component\Routing\Router;
use Strux\Support\ContainerBridge;
use Strux\Support\Helpers\FlashInterface;

class AuthorizationMiddleware implements MiddlewareInterface
{
    private AuthManager $authManager;
    private ResponseFactoryInterface $responseFactory;
    private Router $router;
    private FlashInterface $flash;
    private ?LoggerInterface $logger;
    private string $loginRouteName;

    public function __construct(
        AuthManager              $authManager,
        ResponseFactoryInterface $responseFactory,
        Router                   $router,
        FlashInterface           $flash,
        ?string                  $loginRouteName = null,
        ?string                  $nextParameter = null,
        ?LoggerInterface         $logger = null
    )
    {
        $this->authManager = $authManager;
        $this->responseFactory = $responseFactory;
        $this->router = $router;
        $this->flash = $flash;
        $this->loginRouteName = $loginRouteName;
        $this->nextParameter = $nextParameter;
        $this->logger = $logger;
    }

    /**
     * @throws JsonException
     * @throws ReflectionException
     * @throws AuthorizationException
     */
    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->authManager->sentinel('web')->check()) {
            $userId = $this->authManager->sentinel('web')->id();
            $this->logger?->info("[AuthManagerMiddleware] User with ID {$userId} is authenticated. Proceeding.");
            $routeInfo = $request->getAttribute('route');

            $controller = $routeInfo['controller'] ?? null;
            $method = $routeInfo['method'] ?? null;

            if ($controller && $method) {
                $reflectionClass = new ReflectionClass($controller);
                $this->checkAuthorization($reflectionClass);

                if ($reflectionClass->hasMethod($method)) {
                    $this->checkAuthorization($reflectionClass->getMethod($method));
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

        $this->loginRouteName = $this->loginRouteName
            ?? $config->get('auth.defaults.redirect_to', 'login');

        $this->nextParameter = $this->nextParameter
            ?? $config->get('auth.defaults.next_parameter', 'next');

        try {
            $currentPath = (!empty($request->getUri()->getPath()) && $request->getUri()->getPath() !== '/')
                ? $request->getUri()->getPath()
                : '';

            if (str_starts_with($this->loginRouteName, '/')) {
                if (!empty($currentPath)) {
                    $loginUrl = $this->loginRouteName . '?' . http_build_query([
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

    /**
     * @throws AuthorizationException
     */
    private function checkAuthorization(ReflectionClass|ReflectionMethod $reflector): void
    {
        $attributes = $reflector->getAttributes(Authorize::class);

        foreach ($attributes as $attribute) {
            /** @var Authorize $authAttr */
            $authAttr = $attribute->newInstance();

            /** @var User $user */
            $user = Auth::user();

            if (!$user) {
                throw new AuthorizationException("Unauthenticated.", 401);
            }

            // Check Roles
            if (!empty($authAttr->roles)) {
                if (!$user->hasRole($authAttr->roles)) {
                    throw new AuthorizationException("User does not have the required role.", 403);
                }
            }

            // Check Permissions
            if (!empty($authAttr->permissions)) {
                if (!$user->hasPermission($authAttr->permissions)) {
                    throw new AuthorizationException("User does not have the required permission.", 403);
                }
            }
        }
    }
}
