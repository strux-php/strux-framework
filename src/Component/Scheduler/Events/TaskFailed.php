<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler\Events;

use Strux\Component\Scheduler\Task;
use Throwable;

class TaskFailed
{
    public function __construct(
        public Task $task,
        public Throwable $exception
    ) {
    }
}
