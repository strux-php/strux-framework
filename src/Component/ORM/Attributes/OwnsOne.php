<?php

declare(strict_types=1);

namespace Strux\Component\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OwnsOne extends RelationAttribute
{
    public function __construct(
        public string  $related,
        public ?string $foreignKey = null,
        public ?string $localKey = null,
        public string  $onDelete = 'restrict',
        public string  $onUpdate = 'cascade'
    )
    {
    }
}
