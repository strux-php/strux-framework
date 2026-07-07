<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler;

use Strux\Component\Scheduler\Attributes\Schedule;
use Strux\Component\Scheduler\Attributes\SendOutputTo;
use Strux\Component\Scheduler\Attributes\WithoutOverlapping;

class Task
{
    /**
     * @param string $className
     * @param string|null $methodName null when applied to the class itself (e.g., a Job)
     * @param Schedule $schedule
     * @param WithoutOverlapping|null $withoutOverlapping
     * @param array $runWhens
     * @param SendOutputTo|null $sendOutputTo
     */
    public function __construct(
        public string $className,
        public ?string $methodName,
        public Schedule $schedule,
        public ?WithoutOverlapping $withoutOverlapping = null,
        public array $runWhens = [],
        public ?SendOutputTo $sendOutputTo = null,
        public mixed $extra = null,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->className . ($this->methodName ? '@' . $this->methodName : '');
    }
}
