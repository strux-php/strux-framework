<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class Timestamps
{
    public function __construct(
        public bool   $enabled = true,
        public string $createdAt = 'created_at',
        public string $updatedAt = 'updated_at'
    )
    {
    }
}