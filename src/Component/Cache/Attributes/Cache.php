<?php

declare(strict_types=1);

namespace Strux\Component\Cache\Attributes;

use Attribute;

/**
 * Caches the response of an API endpoint.
 * This attribute should only be used on GET or HEAD requests.
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class Cache
{
    /**
     * @param int $ttl The time-to-live for the cache in seconds.
     */
    public function __construct(public int $ttl)
    {
    }
}
