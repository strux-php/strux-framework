<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OwnedByAny extends RelationAttribute
{
    public function __construct(
        public string $typeColumn,
        public string $idColumn,
        public string $load = 'lazy'
    ) {}
}
