<?php

declare(strict_types=1);

namespace Strux\Auth\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Authorize
{
    public function __construct(
        public array   $roles       = [],
        public array   $permissions = [],
        public ?string $ability     = null,
        public array   $authorities = [],
    ) {}
}
