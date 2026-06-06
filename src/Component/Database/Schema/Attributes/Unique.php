<?php

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Unique
{
    public function __construct(
        public ?string $indexName = null
    )
    {
    }
}