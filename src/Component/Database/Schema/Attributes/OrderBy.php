<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class OrderBy
{
    public function __construct(
        public string $column,
        public string $direction = 'ASC'
    )
    {
    }
}