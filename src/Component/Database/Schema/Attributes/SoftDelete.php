<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class SoftDelete
{
    public function __construct(
        public string $column = 'deleted_at'
    )
    {
    }
}