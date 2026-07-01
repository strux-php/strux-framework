<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Strux\Auth\AuthManager;
use Strux\Auth\Middleware\AuthorizationMiddleware;
use Strux\Component\Config\Config;
use Strux\Component\Middleware\ApiAuthMiddleware;
use Strux\Component\Middleware\ConvertEmptyStringsToNull;
use Strux\Component\Middleware\CsrfProtectionMiddleware;
use Strux\Component\Middleware\ErrorFormatter\HtmlFormatter;
use Strux\Component\Middleware\ErrorFormatter\JsonFormatter;
use Strux\Component\Middleware\ErrorFormatter\PlainFormatter;
use Strux\Component\Middleware\ErrorHandlerMiddleware;
use Strux\Component\Middleware\MaintenanceModeMiddleware;
use Strux\Component\Middleware\MethodOverrideMiddleware;
use Strux\Component\Middleware\PoweredByMiddleware;
use Strux\Component\Middleware\RequestLoggerMiddleware;
use Strux\Component\Routing\Router;
use Strux\Component\Session\SessionInterface;
use Strux\Component\View\ViewInterface;
use Strux\Foundation\Application;
use Strux\Support\Helpers\FlashInterface;
use Throwable;
use Tuupola\Middleware\CorsMiddleware;

class MiddlewareRegistry extends ServiceRegistry
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function build(): void
    {
        $this->buildErrorHandling();

        $this->container->singleton(
            RequestLoggerMiddleware::class,
            static fn(ContainerInterface $c) => new RequestLoggerMiddleware(
                logger: $c->get(LoggerInterface::class)
            )
        );
        $this->container->singleton(MethodOverrideMiddleware::class, static function (ContainerInterface $c) {
            return (new MethodOverrideMiddleware(
                responseFactory: $c->get(ResponseFactoryInterface::class),
                logger: $c->get(LoggerInterface::class)
            ))
                ->getMethods(['HEAD', 'CONNECT', 'TRACE', 'OPTIONS'])
                ->postMethods(['PATCH', 'PUT', 'DELETE', 'COPY', 'LOCK', 'UNLOCK'])
                ->queryParameter('_method')
                ->parsedBodyParameter('_method');
        });
        $this->container->singleton(
            CsrfProtectionMiddleware::class,
            static fn(ContainerInterface $c) => new CsrfProtectionMiddleware(
                session: $c->get(SessionInterface::class),
                logger: $c->get(LoggerInterface::class),
                config: $c->get(Config::class)->get('csrf', [])
            )
        );
        $this->container->singleton(
            AuthorizationMiddleware::class,
            static fn(ContainerInterface $c) => new AuthorizationMiddleware(
                authManager: $c->get(AuthManager::class),
                responseFactory: $c->get(ResponseFactoryInterface::class),
                router: $c->get(Router::class),
                flash: $c->get(FlashInterface::class),
                container: $c,
                logger: $c->get(LoggerInterface::class),
                loginRouteName: $c->get(Config::class)->get('auth.defaults.login_route'),
                nextParameter: $c->get(Config::class)->get('auth.defaults.next_parameter')
            )
        );

        $this->container->singleton(
            ApiAuthMiddleware::class,
            static fn(ContainerInterface $c) => new ApiAuthMiddleware(
                authManager: $c->get(AuthManager::class),
                responseFactory: $c->get(ResponseFactoryInterface::class),
                logger: $c->get(LoggerInterface::class)
            )
        );

        $this->container->singleton(
            MaintenanceModeMiddleware::class,
            static fn(ContainerInterface $c) => new MaintenanceModeMiddleware(
                responseFactory: $c->get(ResponseFactoryInterface::class),
                logger: $c->get(LoggerInterface::class),
                view: $c->get(ViewInterface::class),
                config: $c->get(Config::class)->get('maintenance', [])
            )
        );

        $this->container->singleton(CorsMiddleware::class, static function (ContainerInterface $c) {
            $corsConfig = $c->get(Config::class)->get('cors', []);
            return new CorsMiddleware(
                options: array_merge($corsConfig, [
                    "logger" => $c->get(LoggerInterface::class),
                    "error" => function (ServerRequestInterface $request, ResponseInterface $response, $args) {
                        $data["status"] = "error";
                        $data["message"] = $args["message"];
                        return $response
                            ->withHeader("Content-Type", "application/json")
                            ->getBody()
                            ->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                    }
                ])
            );
        });

        $this->container->singleton(
            PoweredByMiddleware::class,
            static function (ContainerInterface $c) {
                $config = $c->get(Config::class)->get('headers.x_powered_by', []);
                return new PoweredByMiddleware(config: $config);
            }
        );

        $this->container->singleton(
            ConvertEmptyStringsToNull::class,
            static fn() => new ConvertEmptyStringsToNull()
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function init(Application $app): void
    {
        try {
            $app->addMiddleware($this->container->get(ErrorHandlerMiddleware::class));

            $app->addMiddleware($this->container->get(CorsMiddleware::class));

            $app->addMiddleware($this->container->get(PoweredByMiddleware::class));

            $app->addMiddleware($this->container->get(RequestLoggerMiddleware::class));
            $app->addMiddleware($this->container->get(ConvertEmptyStringsToNull::class));
            $app->addMiddleware($this->container->get(MethodOverrideMiddleware::class));
            $app->addMiddleware($this->container->get(CsrfProtectionMiddleware::class));

            if ($this->container->get(Config::class)->get('maintenance.active', false)) {
                $app->addMiddleware($this->container->get(MaintenanceModeMiddleware::class));
            }
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            throw new $e;
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    private function buildErrorHandling(): void
    {
        try {
            $appDebug = $this->config->get('app.debug', false, 'bool');
            $this->container->singleton(HtmlFormatter::class, static function (ContainerInterface $c) use ($appDebug) {
                return new HtmlFormatter(
                    $c->get(ResponseFactoryInterface::class),
                    $c->get(StreamFactoryInterface::class),
                    $appDebug
                );
            });
            $this->container->singleton(JsonFormatter::class, static function (ContainerInterface $c) use ($appDebug) {
                return new JsonFormatter(
                    $c->get(ResponseFactoryInterface::class),
                    $c->get(StreamFactoryInterface::class),
                    $appDebug
                );
            });
            $this->container->singleton(PlainFormatter::class, static function (ContainerInterface $c) use ($appDebug) {
                return new PlainFormatter(
                    $c->get(ResponseFactoryInterface::class),
                    $c->get(StreamFactoryInterface::class),
                    $appDebug
                );
            });
            $this->container->singleton(ErrorHandlerMiddleware::class, static function (ContainerInterface $c) {
                return new ErrorHandlerMiddleware(
                    [
                        $c->get(HtmlFormatter::class),
                        $c->get(JsonFormatter::class),
                        $c->get(PlainFormatter::class)
                    ],
                    $c->get(HtmlFormatter::class),
                    $c->get(LoggerInterface::class)
                );
            });
        } catch (Throwable $e) {
            throw new $e;
        }
    }
}