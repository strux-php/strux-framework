<?php

declare(strict_types=1);

namespace Strux\Component\Exceptions\Http;

use Strux\Component\Http\Response;
use Throwable;

/**
 * Class HttpMethodNotAllowedException
 *
 * Thrown when the HTTP method is not allowed for the requested URI.
 */
class HttpMethodNotAllowedException extends HttpException
{
    /**
     * @var array
     */
    private array $allowedMethods;

    public function __construct(string $message = "405 Method Not Allowed", array $allowedMethods = [], ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        $this->allowedMethods = $allowedMethods;
        $headers['Allow'] = implode(', ', $allowedMethods);
        parent::__construct(Response::HTTP_METHOD_NOT_ALLOWED, $message, $previous, $headers, $code);
    }

    /**
     * Get the allowed HTTP methods.
     *
     * @return array
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}