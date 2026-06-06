<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;
use Strux\Component\Database\Schema\Types\Field;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Column
{
    public function __construct(
        public ?string $name = null,
        public ?Field  $type = null,
        public ?int     $length = 255,
        public ?int     $precision = 10,
        public ?int     $scale = 2,
        public ?bool    $nullable = false,
        public ?bool    $unique = false,
        public mixed   $default = null,
        public ?array  $enums = null,

        public ?bool    $currentTimestamp = false,
        public ?bool    $onUpdateCurrentTimestamp = false
    ) {}
}
