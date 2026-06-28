<?php

declare(strict_types=1);

namespace Strux\Component\Config;

use InvalidArgumentException;
use function array_merge;
use function rtrim;

/**
 * Resolves application directory paths from user-defined overrides
 * merged over sensible framework defaults.
 *
 * Users can configure directories via:
 * 1. A `src/Config/Directories.php` config class
 * 2. Inline array passed to `Kernel::create()`
 * 3. Not at all — defaults just work
 */
class DirectoryResolver implements DirectoryInterface
{
	/**
	 * @var array<string, string> Resolved directory paths
	 */
	private array $directories;

	private string $rootPath;

	/**
	 * @param string $rootPath The application root path
	 * @param array<string, string> $overrides User-defined directory overrides
	 */
	public function __construct(string $rootPath, array $overrides = [])
	{
		$this->rootPath = rtrim($rootPath, '/\\');
		$this->directories = array_merge(self::getDefaults($this->rootPath), $overrides);
	}

	/**
	 * Get the absolute path for the given directory key.
	 *
	 * @throws InvalidArgumentException
	 */
	public function get(string $key): string
	{
		if (!$this->has($key)) {
			throw new InvalidArgumentException(
				"Directory key '{$key}' is not registered. Available keys: " . implode(', ', array_keys($this->directories))
			);
		}

		return $this->directories[$key];
	}

	/**
	 * Check if a directory key exists
	 *
	 * @param string $key
	 * @return bool
	 */
	public function has(string $key): bool
	{
		return array_key_exists($key, $this->directories);
	}

	/**
	 * Get all registered directory paths
	 *
	 * @return array<string, string>
	 */
	public function all(): array
	{
		return $this->directories;
	}

	/**
	 * Get the application root path
	 *
	 * @return string
	 */
	public function rootPath(): string
	{
		return $this->rootPath;
	}

	/**
	 * Set a directory path
	 *
	 * @param string $key
	 * @param string $value
	 * @return void
	 */
	public function set(string $key, string $value): void
	{
		$this->directories[$key] = $value;
	}

	/**
	 * Remove a directory path
	 *
	 * @param string $key
	 * @return void
	 */
	public function remove(string $key): void
	{
		unset($this->directories[$key]);
	}

	/**
	 * Sensible defaults matching the Domain-Driven structure.
	 * Users override any of these to match their preferred layout.
	 *
	 * @return array<string, string>
	 */
	public static function getDefaults(string $rootPath): array
	{
		$root = rtrim($rootPath, '/\\');

		return [
			// Application source
			'app' => $root . '/src',

			// Controllers
			'controllers' => $root . '/src/Http/Controllers/Web',
			'apiControllers' => $root . '/src/Http/Controllers/Api',

			// Domain / Models / Listeners
			'models' => $root . '/src/Domain',
			'listeners' => $root . '/src/Domain',

			// Service Registries
			'registry' => $root . '/src/Registry',

			// Configuration
			'config' => $root . '/src/Config',

			// Views / Templates
			'views' => $root . '/templates',

			// Storage
			'cache' => $root . '/var/cache',
			'logs' => $root . '/var/logs',

			// Database
			'migrations' => $root . '/migrations',
			'seeds' => $root . '/src/Infrastructure/Database/Seeds',

			// Routes
			'routes' => $root . '/etc/routes',

			// Public / Web root
			'public' => $root . '/web',
		];
	}
}
