<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class WithoutOverlapping
{
    public function __construct(
        public int $expiresAfter = 1440 // Minutes before the lock expires automatically
    ) {
    }
}
