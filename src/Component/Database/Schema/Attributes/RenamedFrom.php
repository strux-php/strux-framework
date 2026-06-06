<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class RenamedFrom
{
    public function __construct(
        public string $oldName
    )
    {
    }
}