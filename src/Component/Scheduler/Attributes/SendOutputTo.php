<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class SendOutputTo
{
    public function __construct(
        public string $path,
        public bool $append = false
    ) {
    }
}
