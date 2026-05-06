<?php

declare(strict_types=1);

namespace Strux\Component\Middleware\Attributes;

use Attribute;

/**
 * Class Middleware
 *
 * Defines a middleware attribute for controller classes or methods.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    /** @var array<int, class-string|object> */
    public array $middlewareClasses;

    /**
     * Middleware constructor.
     *
     * @param array|object ...$middlewareClasses One or more middleware class strings or instances.
     */
    public function __construct(array $middlewareClasses = [])
    {
        $this->middlewareClasses = $middlewareClasses;
    }
}
