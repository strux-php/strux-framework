<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Http;

use Strux\Component\Http\Response;
use Throwable;

class ServiceUnavailableHttpException extends HttpException
{
    public function __construct(string $message = 'Service Unavailable', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(Response::HTTP_SERVICE_UNAVAILABLE, $message, $previous, $headers, $code);
    }
}
