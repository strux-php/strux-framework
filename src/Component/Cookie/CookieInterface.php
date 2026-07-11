<?php

declare(strict_types=1);

namespace Strux\Component\Cookie;

interface CookieInterface
{
    public function get(string $key, $default = null): ?string;

    public function set(string $name, string $value = '', array $options = []): bool;

    public function remove(string $name, string $path = '/', string $domain = ''): bool;
}