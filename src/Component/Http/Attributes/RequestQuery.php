<?php

declare(strict_types=1);

namespace Strux\Component\Http\Attributes;

use Attribute;

/**
 * Marks a controller method parameter to be bound from the URL query string.
 * The parameter's type-hint should be a class that extends FormRequest.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class RequestQuery
{
}
