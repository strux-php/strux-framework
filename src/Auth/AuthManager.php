<?php

declare(strict_types=1);

namespace Strux\Auth;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Strux\Component\Config\Config;

class AuthManager
{
	protected array $sentinels = [];
	protected array $customCreators = [];

	public function __construct(
		protected ContainerInterface $container,
		protected ?Config            $config = null
	) {}

	public function sentinel(?string $name = null): SentinelInterface
	{
		$name = $name ?: $this->getDefaultSentinelName();

		if (!isset($this->sentinels[$name])) {
			$this->sentinels[$name] = $this->resolve($name);
		}

		return $this->sentinels[$name];
	}

	protected function resolve(string $name): SentinelInterface
	{
		$config = $this->getConfig($name);

		if (isset($this->customCreators[$name])) {
			return $this->callCustomCreator($name, $config);
		}

		throw new InvalidArgumentException("Auth sentinel driver [{$name}] is not defined.");
	}

	/**
	 * Register a custom sentinel creator closure.
	 */
	public function extend(string $driver, \Closure $callback): self
	{
		$this->customCreators[$driver] = $callback;
		return $this;
	}

	protected function callCustomCreator(string $driver, array $config): SentinelInterface
	{
		return $this->customCreators[$driver]($this->container, $config);
	}

	protected function getDefaultSentinelName(): string
	{
		return $this->config?->get('auth.defaults.sentinel') ?? 'web';
	}

	protected function getConfig(string $name): array
	{
		return $this->config?->get("auth.sentinels.{$name}") ?? [];
	}

	/**
	 * Check if the current user can perform the given ability.
	 * Delegates to the Authorizer service.
	 */
	public function can(string $ability, mixed $arguments = []): bool
	{
		return $this->container->get(Authorizer::class)->allows($ability, $arguments);
	}

	/**
	 * Check if the current user CANNOT perform the given ability.
	 */
	public function cannot(string $ability, mixed $arguments = []): bool
	{
		return !$this->can($ability, $arguments);
	}

	/**
	 * Resolves the appropriate redirect URL for a given authenticated user
	 * based on their roles and the configured redirect_map.
	 */
	public function redirectFor(mixed $user): string
	{
		if (!$this->config) {
			return '/';
		}

		$redirectMap = $this->config->get('auth.defaults.redirect_map', []);
		$defaultRedirect = $this->config->get('auth.defaults.redirect_to', '/');

		if (property_exists($user, 'roles')) {
			foreach ($user->roles as $role) {
				$slug = is_string($role) ? $role : (property_exists($role, 'slug') ? $role->slug : null);
				if ($slug && isset($redirectMap[$slug])) {
					return $redirectMap[$slug];
				}
			}
		}

		return $defaultRedirect;
	}
}
