<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Http;

use Strux\Component\Http\Response;
use Throwable;

class TooManyRequestsHttpException extends HttpException
{
    public function __construct(string $message = 'Too Many Requests', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(Response::HTTP_TOO_MANY_REQUESTS, $message, $previous, $headers, $code);
    }
}
