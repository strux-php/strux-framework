<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Strux\Auth\AuthManager;
use Strux\Auth\Authorizer;
use Strux\Auth\DatabaseUserProvider;
use Strux\Auth\Events\LoginFailed;
use Strux\Auth\Events\UserLoggedIn;
use Strux\Auth\Events\UserLoggedOut;
use Strux\Auth\JwtService;
use Strux\Auth\Listeners\LogAuthenticationAction;
use Strux\Auth\Listeners\UpdateLastLogin;
use Strux\Auth\SessionSentinel;
use Strux\Auth\TokenSentinel;
use Strux\Auth\UserProviderInterface;
use Strux\Component\Config\Config;
use Strux\Component\Events\EventDispatcher;
use Strux\Component\Session\SessionInterface;
use Strux\Foundation\Application;

class AuthRegistry extends ServiceRegistry
{
	public function build(): void
	{
		$this->container->singleton(
			UserProviderInterface::class,
			static fn(ContainerInterface $c) => new DatabaseUserProvider(
				config: $c->get(Config::class)
			)
		);

		$this->container->singleton(
			JwtService::class,
			static fn(ContainerInterface $c) => new JwtService(
				config: $c->get(Config::class)->get('jwt')
			)
		);

		$this->container->singleton(AuthManager::class, static function (ContainerInterface $c) {
			$manager = new AuthManager($c, $c->get(Config::class));

			$manager->extend('web', static function ($c) {
				return new SessionSentinel(
					session: $c->get(SessionInterface::class),
					provider: $c->get(UserProviderInterface::class),
					events: $c->get(EventDispatcher::class)
				);
			});

			$manager->extend('api', static function ($c) {
				return new TokenSentinel(
					jwtService: $c->get(JwtService::class),
					provider: $c->get(UserProviderInterface::class),
					request: $c->get(ServerRequestInterface::class)
				);
			});

			return $manager;
		});

		$this->container->singleton(
			Authorizer::class,
			static fn(ContainerInterface $c) => new Authorizer(
				auth: $c->get(AuthManager::class),
				config: $c->get(Config::class),
				container: $c
			)
		);
	}

	/**
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function init(Application $app): void
	{
		/** @var EventDispatcher $dispatcher */
		$dispatcher = $app->getContainer()->get(EventDispatcher::class);

		/** @var LoggerInterface $logger */
		$logger = $app->getContainer()->get(LoggerInterface::class);

		$dispatcher->addListener(UserLoggedIn::class, [new UpdateLastLogin(), 'handle']);

		$logListener = new LogAuthenticationAction($logger);

		$dispatcher->addListener(UserLoggedIn::class, [$logListener, 'onLogin']);
		$dispatcher->addListener(UserLoggedOut::class, [$logListener, 'onLogout']);
		$dispatcher->addListener(LoginFailed::class, [$logListener, 'onFailure']);
	}
}
