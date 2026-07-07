<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler\Events;

use Strux\Component\Scheduler\Task;

class TaskSuccess
{
    public function __construct(
        public Task $task,
        public mixed $result = null
    ) {
    }
}
