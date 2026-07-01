<?php

declare(strict_types=1);

namespace Strux\Auth;

use InvalidArgumentException;
use Strux\Auth\Events\Authenticated;
use Strux\Auth\Events\LoginFailed;
use Strux\Auth\Events\LoggedOut;
use Strux\Auth\Events\Validated;
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
    private const string REMEMBER_COOKIE_NAME = 'remember_me';
    private const int DEFAULT_REMEMBER_DURATION = 2592000; // 30 days

    private int $rememberDuration;
    private string $rememberCookiePath;
    private string $rememberCookieDomain;
    private bool $rememberCookieSecure;
    private bool $rememberCookieHttpOnly;

    public function __construct(
        SessionInterface      $session,
        UserProviderInterface $provider,
        EventDispatcher       $events,
        array                 $config = []
    ) {
        $this->session = $session;
        $this->provider = $provider;
        $this->events = $events;

        $this->rememberDuration = (int)($config['remember_duration'] ?? self::DEFAULT_REMEMBER_DURATION);
        $this->rememberCookiePath = $config['cookie_path'] ?? '/';
        $this->rememberCookieDomain = $config['cookie_domain'] ?? '';
        $this->rememberCookieSecure = (bool)($config['cookie_secure'] ?? false);
        $this->rememberCookieHttpOnly = (bool)($config['cookie_http_only'] ?? true);
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

        // If no session user, try remember-me cookie
        if ($this->user === null) {
            $this->user = $this->recallUserFromCookie();
        }

        $this->userLoaded = true;
        return $this->user;
    }

    private function recallUserFromCookie(): ?object
    {
        if (empty($_COOKIE[self::REMEMBER_COOKIE_NAME])) {
            return null;
        }

        $cookie = $_COOKIE[self::REMEMBER_COOKIE_NAME];
        $decoded = base64_decode($cookie, true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            $this->clearRememberCookie();
            return null;
        }

        [$id, $token] = explode(':', $decoded, 2);

        if (empty($id) || empty($token)) {
            $this->clearRememberCookie();
            return null;
        }

        $user = $this->provider->retrieveById($id);

        if ($user === null) {
            $this->clearRememberCookie();
            return null;
        }

        $storedHash = $user->remember_token ?? null;

        if ($storedHash === null || !hash_equals($storedHash, hash('sha256', $token))) {
            $this->clearRememberCookie();
            return null;
        }

        // Token is valid — re-login and rotate the token
        $this->session->set(self::SESSION_USER_ID_KEY, $id);
        $this->session->regenerateId(true);

        $this->rotateRememberToken($user);

        return $user;
    }

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

        $this->events->dispatch(new Validated($user));

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

            if ($remember) {
                $this->setRememberCookie($user);
            }

            $this->events->dispatch(new Authenticated($user));
        } else {
            throw new InvalidArgumentException("User object must have a valid identifier.");
        }
    }

    public function logout(): void
    {
        $user = $this->user();

        $this->session->remove(self::SESSION_USER_ID_KEY);
        $this->session->regenerateId(true);

        // Clear remember-me cookie and token
        $this->clearRememberTokenInDb($user);
        $this->clearRememberCookie();

        $this->user = null;
        $this->userLoaded = false;

        if ($user) {
            $this->events->dispatch(new LoggedOut($user));
        }
    }

    public function setUser(object $user): void
    {
        $this->user = $user;
        $this->userLoaded = true;
    }

    private function setRememberCookie(object $user): void
    {
        $token = bin2hex(random_bytes(60));
        $hash = hash('sha256', $token);

        // Store hash in DB
        if (property_exists($user, 'remember_token')) {
            $user->remember_token = $hash;
            if (method_exists($user, 'save')) {
                $user->save();
            }
        }

        $id = $this->getUserIdFromObject($user);
        $encoded = base64_encode($id . ':' . $token);

        setcookie(
            self::REMEMBER_COOKIE_NAME,
            $encoded,
            [
                'expires' => time() + $this->rememberDuration,
                'path' => $this->rememberCookiePath,
                'domain' => $this->rememberCookieDomain,
                'secure' => $this->rememberCookieSecure,
                'httponly' => $this->rememberCookieHttpOnly,
                'samesite' => 'Lax',
            ]
        );
    }

    private function rotateRememberToken(object $user): void
    {
        $token = bin2hex(random_bytes(60));
        $hash = hash('sha256', $token);

        if (property_exists($user, 'remember_token')) {
            $user->remember_token = $hash;
            if (method_exists($user, 'save')) {
                $user->save();
            }
        }

        $id = $this->getUserIdFromObject($user);
        $encoded = base64_encode($id . ':' . $token);

        setcookie(
            self::REMEMBER_COOKIE_NAME,
            $encoded,
            [
                'expires' => time() + $this->rememberDuration,
                'path' => $this->rememberCookiePath,
                'domain' => $this->rememberCookieDomain,
                'secure' => $this->rememberCookieSecure,
                'httponly' => $this->rememberCookieHttpOnly,
                'samesite' => 'Lax',
            ]
        );
    }

    private function clearRememberTokenInDb(?object $user): void
    {
        if ($user && property_exists($user, 'remember_token') && $user->remember_token !== null) {
            $user->remember_token = null;
            if (method_exists($user, 'save')) {
                $user->save();
            }
        }
    }

    private function clearRememberCookie(): void
    {
        if (empty($_COOKIE[self::REMEMBER_COOKIE_NAME])) {
            return;
        }

        setcookie(
            self::REMEMBER_COOKIE_NAME,
            '',
            [
                'expires' => time() - 3600,
                'path' => $this->rememberCookiePath,
                'domain' => $this->rememberCookieDomain,
                'secure' => $this->rememberCookieSecure,
                'httponly' => $this->rememberCookieHttpOnly,
                'samesite' => 'Lax',
            ]
        );

        unset($_COOKIE[self::REMEMBER_COOKIE_NAME]);
    }
}
