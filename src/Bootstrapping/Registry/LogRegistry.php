<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Monolog\Handler\BrowserConsoleHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Strux\Component\Log\CliLogger;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\Config\DirectoryResolver;
use Strux\Foundation\Application;
use Strux\Support\Bridge\Config;

class LogRegistry extends ServiceRegistry
{
	public function build(): void
	{
		if (php_sapi_name() === 'cli') {
			$this->container->singleton(LoggerInterface::class, fn() => new CliLogger());
		} else {
			$this->container->singleton(LoggerInterface::class, static function (ContainerInterface $c) {
				$logger = new Logger(Config::get('app.log.name', 'app'));
				$env = Config::get('app.env', 'production');
				if ($env === 'development') {
					$logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));
					$logger->pushHandler(new BrowserConsoleHandler(Level::Debug));
				} else {
					$logger->pushHandler(new RotatingFileHandler(
						(Config::get('app.log.path') ?? Config::get('app.log_dir')) . '/app.log',
						7,
						Level::Warning
					));
				}
				return $logger;
			});
		}
	}

	public function init(Application $app): void
	{
		$logDir = $app->getContainer()->has(DirectoryInterface::class)
			? $app->getContainer()->get(DirectoryInterface::class)->get('logs')
			: DirectoryResolver::getDefaults($app->getRootPath())['logs'];

		Config::set('app.log_dir', $logDir);
	}
}
