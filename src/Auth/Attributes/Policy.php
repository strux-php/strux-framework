<?php

declare(strict_types=1);

namespace Strux\Auth\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Policy
{
    public function __construct(
        public string $policy
    ) {}
}
