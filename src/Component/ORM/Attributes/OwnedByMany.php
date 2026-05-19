<?php

declare(strict_types=1);

namespace Strux\Component\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OwnedByMany extends RelationAttribute
{
    public function __construct(
        public string  $related,
        public ?string $pivotTable = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public string  $onDelete = 'cascade',
        public string  $onUpdate = 'cascade'
    )
    {
    }
}
