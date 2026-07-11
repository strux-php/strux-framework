<?php

declare(strict_types=1);

namespace Strux\Component\Cookie;

use Strux\Component\Config\Config;

class Cookie implements CookieInterface
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Retrieve a cookie from the request.
     */
    public function get(string $key, $default = null): ?string
    {
        return $_COOKIE[$key] ?? $default;
    }

    /**
     * Set a cookie. This will be sent with the next response.
     *
     * @param string $name The name of the cookie.
     * @param string $value The value of the cookie.
     * @param array $options An array of options: 'expires', 'path', 'domain', 'secure', 'httponly', 'samesite'.
     * Options will default to values from etc/session.php.
     */
    public function set(string $name, string $value = '', array $options = []): bool
    {
        $defaults = $this->getDefaultCookieOptions();

        $options['expires'] = $options['expires'] ?? $defaults['expires'];
        $options['path'] = $options['path'] ?? $defaults['path'];
        $options['domain'] = $options['domain'] ?? $defaults['domain'];
        $options['secure'] = $options['secure'] ?? $defaults['secure'];
        $options['httponly'] = $options['httponly'] ?? $defaults['httponly'];
        $options['samesite'] = $options['samesite'] ?? $defaults['samesite'];

        return setcookie($name, $value, $options);
    }

    /**
     * Remove a cookie by setting its expiration date in the past.
     */
    public function remove(string $name, string $path = '/', string $domain = ''): bool
    {
        $options = [
            'expires' => time() - 3600,
            'path' => $path,
            'domain' => $domain
        ];
        return $this->set($name, '', $options);
    }

    /**
     * Get the default cookie options from the session configuration.
     */
    private function getDefaultCookieOptions(): array
    {
        $sessionConfig = $this->config->get('session', []);

        $lifetime = (int)($sessionConfig['lifetime'] ?? 120);
        $expires = ($lifetime > 0) ? time() + ($lifetime * 60) : 0;

        return [
            'expires' => $expires,
            'path' => $sessionConfig['path'] ?? '/',
            'domain' => $sessionConfig['domain'] ?? '',
            'secure' => $sessionConfig['secure'] ?? false,
            'httponly' => $sessionConfig['http_only'] ?? true,
            'samesite' => $sessionConfig['same_site'] ?? 'Lax',
        ];
    }
}
