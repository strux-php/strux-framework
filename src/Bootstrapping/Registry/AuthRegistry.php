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
use Strux\Auth\Events\Authenticated;
use Strux\Auth\Events\LoginFailed;
use Strux\Auth\Events\LoggedOut;
use Strux\Auth\Events\PasswordReset;
use Strux\Auth\Events\Registered;
use Strux\Auth\Events\Validated;
use Strux\Auth\Events\Verified;
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
				$config = $c->get(Config::class);

				return new SessionSentinel(
					session: $c->get(SessionInterface::class),
					provider: $c->get(UserProviderInterface::class),
					events: $c->get(EventDispatcher::class),
					config: $config->get('auth.sentinels.web', [])
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

		$dispatcher->addListener(Authenticated::class, [new UpdateLastLogin(), 'handle']);

		$logListener = new LogAuthenticationAction($logger);

		$dispatcher->addListener(Authenticated::class, [$logListener, 'onLogin']);
		$dispatcher->addListener(LoggedOut::class, [$logListener, 'onLogout']);
		$dispatcher->addListener(LoginFailed::class, [$logListener, 'onFailure']);
		$dispatcher->addListener(Registered::class, [$logListener, 'onRegistered']);
		$dispatcher->addListener(Validated::class, [$logListener, 'onValidated']);
		$dispatcher->addListener(Verified::class, [$logListener, 'onVerified']);
		$dispatcher->addListener(PasswordReset::class, [$logListener, 'onPasswordReset']);
	}
}
