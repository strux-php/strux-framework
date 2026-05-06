<?php

declare(strict_types=1);

namespace Strux\Component\Http\Attributes;

use Attribute;

/**
 * Adds a header to the response of an API endpoint.
 * This attribute is repeatable.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
readonly class ResponseHeader
{
    public function __construct(
        public string $key,
        public string $value
    )
    {
    }
}
