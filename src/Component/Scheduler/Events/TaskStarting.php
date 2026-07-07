<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler\Events;

use Strux\Component\Scheduler\Task;

class TaskStarting
{
    public function __construct(
        public Task $task
    ) {
    }
}
