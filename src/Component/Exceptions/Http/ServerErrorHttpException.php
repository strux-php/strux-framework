<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Http;

use Strux\Component\Http\Response;
use Throwable;

class ServerErrorHttpException extends HttpException
{
    public function __construct(string $message = 'Internal Server Error', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(Response::HTTP_INTERNAL_SERVER_ERROR, $message, $previous, $headers, $code);
    }
}
