<?php

declare(strict_types=1);

namespace Strux\Component\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ConvertEmptyStringsToNull implements MiddlewareInterface
{
	/**
	 * Process an incoming server request.
	 */
	public function process(
		ServerRequestInterface  $request,
		RequestHandlerInterface $handler
	): ResponseInterface {
		$parsedBody = $request->getParsedBody();

		if (is_array($parsedBody)) {
			$modifiedBody = $this->transform($parsedBody);
			$request = $request->withParsedBody($modifiedBody);
		}

		return $handler->handle($request);
	}

	/**
	 * Recursively transforms empty strings in an array to null.
	 */
	protected function transform(array $data): array
	{
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = $this->transform($value);
			} elseif ($value === '') {
				$data[$key] = null;
			}
		}

		return $data;
	}
}
