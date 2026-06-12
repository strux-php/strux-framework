<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Http;

use Strux\Component\Http\Response;
use Throwable;

class ConflictHttpException extends HttpException
{
    public function __construct(string $message = 'Conflict', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(Response::HTTP_CONFLICT, $message, $previous, $headers, $code);
    }
}
