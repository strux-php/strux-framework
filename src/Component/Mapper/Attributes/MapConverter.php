<?php

declare(strict_types=1);

namespace Strux\Component\Mapper\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class MapConverter
{
    /**
     * @param string $converterClass A class name that implements ConverterInterface
     */
    public function __construct(public string $converterClass) {}
}
