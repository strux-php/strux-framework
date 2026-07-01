<?php

declare(strict_types=1);

namespace Strux\Auth\Events;

class LoggedOut
{
    public function __construct(
        public ?object $user = null
    )
    {
    }
}
