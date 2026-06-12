<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
abstract class RelationAttribute
{
    public string $load = 'lazy';

    // This abstract class serves as a common parent for all relationship attributes.
    // This allows us to easily find all relationship attributes on a model
    // using Reflection: $reflection->getAttributes(RelationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
}
