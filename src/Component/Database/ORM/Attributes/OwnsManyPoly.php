<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OwnsManyPoly extends RelationAttribute
{
    public function __construct(
        public string $related,
        public string $typeColumn,
        public string $idColumn,
        public string $load = 'lazy'
    ) {}
}
