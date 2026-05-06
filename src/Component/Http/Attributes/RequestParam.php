<?php

declare(strict_types=1);

namespace Strux\Component\Http\Attributes;

use Attribute;

/**
 * Binds a controller method parameter to a route URL parameter.
 * This is primarily for clarity and can be extended for more complex binding.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestParam
{
    /**
     * @param string|null $name The name of the route parameter. If null, it's inferred from the method's argument name.
     */
    public function __construct(
        public ?string $name = null
    )
    {
    }
}
