<?php

declare(strict_types=1);

namespace Strux\Auth\Events;

class PasswordReset
{
    public function __construct(
        public object $user
    )
    {
    }
}
