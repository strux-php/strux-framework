<?php

declare(strict_types=1);

namespace Strux\Support\Bridge;

use Strux\Component\Cookie\CookieInterface;
use Strux\Support\FrameworkBridge;

/**
 * @method static ?string get(string $key, $default = null)
 * @method static bool set(string $name, string $value = '', array $options = [])
 * @method static bool remove(string $name, string $path = '/', string $domain = '')
 * @method static array getDefaultCookieOptions()
 * @see \Strux\Component\Cookie\Cookie
 */
class Cookie extends FrameworkBridge
{
    /**
     * @inheritDoc
     */
    protected static function getAccessor(): string
    {
        return CookieInterface::class;
    }
}