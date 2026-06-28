<?php

declare(strict_types=1);

namespace Strux\Auth;

use InvalidArgumentException;
use Strux\Auth\Events\LoginFailed;
use Strux\Auth\Events\UserLoggedIn;
use Strux\Auth\Events\UserLoggedOut;
use Strux\Component\Events\EventDispatcher;
use Strux\Component\Session\SessionInterface;

class SessionSentinel implements SentinelInterface
{
	private SessionInterface $session;
	private UserProviderInterface $provider;
	private EventDispatcher $events;

	private ?object $user = null;
	private bool $userLoaded = false;

	private const string SESSION_USER_ID_KEY = 'auth_user_id';

	public function __construct(
		SessionInterface      $session,
		UserProviderInterface $provider,
		EventDispatcher       $events
	) {
		$this->session = $session;
		$this->provider = $provider;
		$this->events = $events;
	}

	public function isAuthenticated(): bool
	{
		return $this->user() !== null;
	}

	public function user(): ?object
	{
		if ($this->userLoaded) return $this->user;

		$id = $this->session->get(self::SESSION_USER_ID_KEY);
		if ($id) {
			$this->user = $this->provider->retrieveById($id);
			if ($this->user === null) {
				$this->session->remove(self::SESSION_USER_ID_KEY);
			}
		}

		$this->userLoaded = true;
		return $this->user;
	}

	/**
	 * Get the User ID from the object safely.
	 */
	private function getUserIdFromObject(object $user): string|int|null
	{
		if (method_exists($user, 'getPrimaryKey')) {
			$pk = $user->getPrimaryKey();

			if (isset($user->{$pk})) {
				return $user->{$pk};
			}

			$getter = 'get' . ucfirst($pk);
			if (method_exists($user, $getter)) {
				return $user->{$getter}();
			}
		}

		return $user->id ?? $user->userId ?? $user->userID ?? $user->user_id ?? null;
	}

	public function id(): int|string|null
	{
		$user = $this->user();
		if (!$user) {
			return null;
		}
		return $this->getUserIdFromObject($user);
	}

	public function validate(array $credentials = []): bool
	{
		$user = $this->provider->retrieveByCredentials($credentials);

		if (!$user || !$this->provider->validateCredentials($user, $credentials)) {
			$this->events->dispatch(new LoginFailed($credentials));
			return false;
		}

		return true;
	}

	public function authenticate(array|object $credentials = [], bool $remember = false): bool
	{
		if (is_object($credentials)) {
			$this->login($credentials, $remember);
			return true;
		}

		if ($this->validate($credentials)) {
			$user = $this->provider->retrieveByCredentials($credentials);
			$this->login($user, $remember);
			return true;
		}
		return false;
	}

	public function login(object $user, bool $remember = false): void
	{
		$id = $this->getUserIdFromObject($user);

		if ($id) {
			$this->session->set(self::SESSION_USER_ID_KEY, $id);
			$this->session->regenerateId(true);

			$this->user = $user;
			$this->userLoaded = true;

			$this->events->dispatch(new UserLoggedIn($user));
		} else {
			throw new InvalidArgumentException("User object must have a valid identifier.");
		}
	}

	public function logout(): void
	{
		$user = $this->user();

		$this->session->remove(self::SESSION_USER_ID_KEY);
		$this->session->regenerateId(true);
		$this->user = null;
		$this->userLoaded = false;

		if ($user) {
			$this->events->dispatch(new UserLoggedOut($user));
		}
	}

	public function setUser(object $user): void
	{
		$this->user = $user;
		$this->userLoaded = true;
	}
}
