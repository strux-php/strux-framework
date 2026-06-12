<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Http;

use Strux\Component\Http\Response;
use Throwable;

class UnsupportedMediaTypeHttpException extends HttpException
{
    public function __construct(string $message = "415 Unsupported Media Type", ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $message, $previous, $headers, $code);
    }
}