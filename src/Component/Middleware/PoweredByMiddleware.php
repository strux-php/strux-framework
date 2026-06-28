<?php

declare(strict_types=1);

namespace Strux\Component\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PoweredByMiddleware implements MiddlewareInterface
{
    private bool $enabled;
    private string $value;

    public function __construct(array $config)
    {
        $this->enabled = (bool)($config['enabled'] ?? true);
        $this->value = $config['value'] ?? 'Strux Framework';
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // TODO: Use the PSR server request to remove the following header
        header_remove('X-Powered-By');

        if ($this->enabled) {
            return $response->withHeader('X-Powered-By', $this->value);
        }

        return $response;
    }
}
