<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Strux\Component\Cache\Cache;
use Strux\Component\Config\Config;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\Http\Cookie;
use Strux\Component\Http\CookieInterface;
use Strux\Component\Mail\Mailer;
use Strux\Component\Mail\MailerInterface;
use Strux\Component\Session\SessionInterface;
use Strux\Component\Session\SessionManager;
use Strux\Component\Validation\Validator;
use Strux\Component\Validation\ValidatorInterface;
use Strux\Component\View\ViewInterface;
use Strux\Component\Filesystem\Filesystem;
use Strux\Component\Filesystem\FilesystemInterface;
use Strux\Component\Mapper\Mapper;
use Strux\Component\Mapper\MapperInterface;
use Strux\Support\Helpers\Flash;
use Strux\Support\Helpers\FlashInterface;

class InfrastructureRegistry extends ServiceRegistry
{
	public function build(): void
	{
		$this->container->singleton(
			FilesystemInterface::class,
			static fn(ContainerInterface $c) => new Filesystem()
		);

		$this->container->singleton(
			SessionInterface::class,
			static fn(ContainerInterface $c) => new SessionManager(
				config: $c->get(Config::class),
				container: $c
			)
		);

		$this->container->singleton(
			CookieInterface::class,
			static fn(ContainerInterface $c) => new Cookie(
				config: $c->get(Config::class)
			)
		);

		$this->container->singleton(
			FlashInterface::class,
			static fn(ContainerInterface $c) => new Flash(
				session: $c->get(SessionInterface::class)
			)
		);

		$this->container->singleton(
			CacheInterface::class,
			static fn(ContainerInterface $c) => new Cache(
				config: $c->get(Config::class),
				logger: $c->get(LoggerInterface::class),
				events: $c->get(EventDispatcherInterface::class)
			)
		);

		$this->container->bind(
			ValidatorInterface::class,
			static function (ContainerInterface $c) {
				/** @var ServerRequestInterface $parsedBody */
				$parsedBody = $c->get(ServerRequestInterface::class);
				return new Validator(
					postData: $parsedBody->getParsedBody() ?? []
				);
			}
		);

		$this->container->transient(
			MailerInterface::class,
			static fn(ContainerInterface $c) => new Mailer(
				config: $c->get(Config::class),
				dirs: $c->get(DirectoryInterface::class),
				view: $c->get(ViewInterface::class),
				logger: $c->get(LoggerInterface::class)
			)
		);

		$this->container->singleton(
			MapperInterface::class,
			static fn(ContainerInterface $c) => new Mapper()
		);
	}
}
