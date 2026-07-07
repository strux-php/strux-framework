<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Schedule
{
    public function __construct(
        public ?string $expression = null,
        public ?string $frequency = null,
        public ?string $timezone = null
    ) {
    }

    public function getExpression(): string
    {
        return $this->expression ?? $this->frequency ?? '* * * * *';
    }
}
