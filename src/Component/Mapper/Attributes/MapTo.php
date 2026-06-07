<?php

declare(strict_types=1);

namespace Strux\Component\Mapper\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class MapTo
{
    public function __construct(public string $key) {}
}
