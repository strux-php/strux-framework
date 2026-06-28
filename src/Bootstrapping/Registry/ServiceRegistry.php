<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Psr\Container\ContainerInterface;
use Strux\Component\Config\Config;
use Strux\Foundation\Application;
use Strux\Foundation\Container;
use Strux\Support\ContainerBridge;

abstract class ServiceRegistry implements ServiceRegistryInterface
{
	/** @var Container $container */
	protected ?ContainerInterface $container = null;
	protected ?Config $config = null;

	public function __construct(
		/** @var Container $container */
		?ContainerInterface $container = null,
		?Config             $config = null
	) {
		$this->container = $container;
		$this->config = $config;

		if ($this->container === null || $this->config === null) {
			try {
				$this->container = ContainerBridge::get(ContainerInterface::class);
				$this->config = ContainerBridge::get(Config::class);
			} catch (\Throwable $e) {
			}
		}
	}

	/**
	 * All registries must define how they build their services.
	 */
	abstract public function build(): void;

	/**
	 * The init method is optional for registries.
	 */
	public function init(Application $app): void
	{
		// This method can be overridden by child registries if they need init logic.
	}
}
