<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\Config\DirectoryResolver;
use Strux\Foundation\Application;
use Strux\Support\ContainerBridge;

class AppRegistry extends ServiceRegistry
{
	protected ?ContainerInterface $container;

	/**
	 * @var array<int, object>
	 */
	protected array $registries = [];

	/**
	 * Core registries that are always loaded.
	 */
	protected array $coreRegistries = [
		LogRegistry::class,
		DatabaseRegistry::class,
		AuthRegistry::class,
		HttpRegistry::class,
		RouteRegistry::class,
		ViewRegistry::class,
		EventRegistry::class,
		MiddlewareRegistry::class,
		InfrastructureRegistry::class,
		QueueRegistry::class,
		SchedulerRegistry::class,
		FormRegistry::class,
	];

	public function __construct(?ContainerInterface $container)
	{
		$this->container = $container ?? ContainerBridge::get(ContainerInterface::class);
		parent::__construct($container);
	}

	/**
	 * Build and Register Services (Bindings).
	 * @throws ReflectionException
	 */
	public function build(): void
	{
		foreach ($this->coreRegistries as $registryClass) {
			$this->instantiateAndBuild($registryClass);
		}

		$this->discoverUserRegistries();
	}

	/**
	 * Initialize/Boot Services (After bindings are complete).
	 *
	 * @param Application $app
	 */
	public function init(Application $app): void
	{
		/**  * @var ServiceRegistry $registry */
		foreach ($this->registries as $registry) {
			if (method_exists($registry, 'init')) {
				$registry->init($app);
			} elseif (method_exists($registry, 'boot')) {
				$registry->boot($app);
			}
		}
	}

	/**
	 * Register a registry instance or class name.
	 *
	 * @param string|object $registry
	 * @throws ReflectionException
	 */
	protected function instantiateAndBuild(string|object $registry): void
	{
		/** @var ServiceRegistry $registry */
		// Standard Registry
		if (is_string($registry)) {
			if (!class_exists($registry)) {
				return;
			}
			$registry = new $registry($this->container);
		}
		// Anonymous Registry
		elseif (is_object($registry)) {
			$this->injectContainer($registry);
		}

		$this->registries[] = $registry;

		if (method_exists($registry, 'build')) {
			$registry->build();
		} else {
			throw new \RuntimeException("ServiceRegistry class " . get_class($registry) . " must implement a build() method.");
		}
	}

	/**
	 * Helper to inject container into an instantiated registry object.
	 * Essential for anonymous classes which bypass the __construct($container) call.
	 * @throws ReflectionException
	 */
	protected function injectContainer(object $registry): void
	{
		if (property_exists($registry, 'container')) {
			$reflection = new ReflectionClass($registry);
			$property = $reflection->getProperty('container');

			if (!$property->isPublic()) {
				$property->setAccessible(true);
			}

			if (!$property->isInitialized($registry) || $property->getValue($registry) === null) {
				$property->setValue($registry, $this->container);
			}
		}
	}

	/**
	 * Scan the App/Registry directory for user-defined registries.
	 * @throws ReflectionException
	 */
	protected function discoverUserRegistries(): void
	{
		if ($this->container->has(DirectoryInterface::class)) {
			/** @var DirectoryInterface $dirs */
			$dirs = $this->container->get(DirectoryInterface::class) ?? ContainerBridge::resolve(DirectoryInterface::class);
			$registryDir = $dirs->get('registry');
		} else {
			$rootPath = defined('ROOT_PATH') ? ROOT_PATH : getcwd();
			$registryDir = DirectoryResolver::getDefaults($rootPath)['registry'];
		}

		if (!is_dir($registryDir)) {
			return;
		}

		$files = glob($registryDir . '/*.php');

		foreach ($files as $file) {
			// This supports: return new class extends ServiceRegistry { ... };
			$returned = include_once $file;

			if (is_object($returned)) {
				$this->instantiateAndBuild($returned);
				continue;
			}

			// This supports: class AppRegistry extends ServiceRegistry { ... }
			$filename = basename($file, '.php');
			$className = "App\\Registry\\{$filename}";

			if (class_exists($className)) {
				$this->instantiateAndBuild($className);
			}
		}
	}
}
