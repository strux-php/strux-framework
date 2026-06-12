<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Database;

use Strux\Component\Exceptions\DatabaseException;

class LazyLoadingViolationException extends DatabaseException
{
    public function __construct(string $model, string $relation)
    {
        parent::__construct(sprintf(
            "Attempted to lazy load relation '%s' on model '%s' but lazy loading is disabled.",
            $relation,
            $model
        ));
    }
}
