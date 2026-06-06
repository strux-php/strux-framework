<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Id
{
    public function __construct(
        public bool   $autoincrement = true,
        public string $autoGenerate = 'none' // Options: 'none', 'uuid', 'ulid'
    )
    {
    }
}