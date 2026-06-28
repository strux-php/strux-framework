<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\Routing\ParameterResolver;
use Strux\Component\Routing\RouteDispatcher;
use Strux\Component\Routing\Router;
use Strux\Component\Routing\RouterLoader;
use Strux\Foundation\Application;

class RouteRegistry extends ServiceRegistry
{
	public function build(): void
	{
		$this->container->singleton(
			Router::class,
			static fn(ContainerInterface $c) => new Router(
				currentRequest: $c->get(ServerRequestInterface::class)
			)
		);

		$this->container->singleton(
			RouterLoader::class,
			static fn(ContainerInterface $c) => new RouterLoader(
				router: $c->get(Router::class),
				container: $c,
				logger: $c->get(LoggerInterface::class)
			)
		);

		$this->container->singleton(
			ParameterResolver::class,
			static fn(ContainerInterface $c) => new ParameterResolver(
				container: $c
			)
		);

		$this->container->singleton(
			RouteDispatcher::class,
			static fn(ContainerInterface $c) => new RouteDispatcher(
				container: $c,
				router: $c->get(Router::class),
				parameterResolver: $c->get(ParameterResolver::class)
			)
		);
	}

	/**
	 * @throws ContainerExceptionInterface
	 * @throws ReflectionException
	 * @throws NotFoundExceptionInterface
	 */
	public function init(Application $app): void
	{
		$router = $app->getRouter();
		/** @var RouterLoader $routerLoader */
		$routerLoader = $this->container->get(RouterLoader::class);

		/** @var DirectoryInterface $dirs */
		$dirs = $this->container->get(DirectoryInterface::class);

		// Legacy route files
		$legacyRoutesPath = $dirs->get('routes') . '/web.php';
		if (file_exists($legacyRoutesPath)) {
			require $legacyRoutesPath;
		}

		// Auto-Discover Attribute-Based Web Controllers
		$webControllerDir = $dirs->get('controllers');
		if (is_dir($webControllerDir)) {
			$routerLoader->loadFromDirectory($webControllerDir, isApi: false);
		}

		// Auto-Discover Attribute-Based API Controllers
		$apiControllerDir = $dirs->get('apiControllers');
		if (is_dir($apiControllerDir)) {
			$routerLoader->loadFromDirectory($apiControllerDir, isApi: true);
		}
	}
}
