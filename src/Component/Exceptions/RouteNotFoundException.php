<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions;

use Strux\Component\Exceptions\Http\NotFoundHttpException;
use Throwable;

/**
 * Class RouteNotFoundException
 *
 * Thrown when no route matches the requested URI.
 */
class RouteNotFoundException extends NotFoundHttpException
{
    public function __construct(string $message = "404 Not Found", ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct($message, $previous, $headers, $code);
    }
}