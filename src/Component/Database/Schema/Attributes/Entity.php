<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class Entity
{
    public function __construct(
        public ?string $table = null,
        public ?string $database = null,
        public ?string $connection = null,
        public ?string $schema = null,
        public bool $readOnly = false,
        public ?string $mapper = null
    )
    {
    }
}