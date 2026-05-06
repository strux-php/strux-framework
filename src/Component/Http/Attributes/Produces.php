<?php

declare(strict_types=1);

namespace Strux\Component\Http\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Produces
{
    public string $mediaType;

    public function __construct(string $mediaType)
    {
        $this->mediaType = $mediaType;
    }
}
