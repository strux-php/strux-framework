<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RunWhen
{
    /**
     * @param string|callable $condition A callable or a container service method e.g. "App\Services\MyService@shouldRun"
     */
    public function __construct(
        public mixed $condition
    ) {
    }
}
