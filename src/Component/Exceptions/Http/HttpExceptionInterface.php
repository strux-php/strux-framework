<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Http;

use Throwable;

interface HttpExceptionInterface extends Throwable
{
    /**
     * Returns the HTTP status code.
     */
    public function getStatusCode(): int;

    /**
     * Returns response headers.
     */
    public function getHeaders(): array;
}
