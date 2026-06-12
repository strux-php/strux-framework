<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Http;

use Strux\Component\Http\Response;
use Throwable;

class NotAcceptableHttpException extends HttpException
{
    public function __construct(string $message = 'Not Acceptable', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(Response::HTTP_NOT_ACCEPTABLE, $message, $previous, $headers, $code);
    }
}
