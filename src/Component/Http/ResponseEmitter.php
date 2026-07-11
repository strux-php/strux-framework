<?php

declare(strict_types=1);

namespace Strux\Component\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-7 Response Emitter.
 * Sends the response headers and body to the client.
 * Based on the excellent implementation from Slim Framework.
 */
class ResponseEmitter
{
	private int $responseChunkSize;

	public function __construct(int $responseChunkSize = 8192)
	{
		$this->responseChunkSize = $responseChunkSize;
	}

	/**
	 * Emits the PSR-7 Response to the client.
	 */
	public function emit(ResponseInterface $response): void
	{
		if (headers_sent()) {
			error_log('Unable to emit response; headers already sent.');
			return;
		}

		if (ob_get_level() > 0 && ob_get_length() > 0) {
			error_log('Unable to emit response; output has been emitted previously.');
			return;
		}

		// 1. Emit Status Line
		$this->emitStatusLine($response);

		// 2. Emit Headers
		$this->emitHeaders($response);

		// 3. Emit Body (if not an empty response type)
		if (!$this->isResponseEmpty($response)) {
			$this->emitBody($response);
		}
	}

	private function emitStatusLine(ResponseInterface $response): void
	{
		$statusLine = sprintf(
			'HTTP/%s %s %s',
			$response->getProtocolVersion(),
			$response->getStatusCode(),
			$response->getReasonPhrase()
		);
		header($statusLine, true, $response->getStatusCode());
	}

	private function emitHeaders(ResponseInterface $response): void
	{
		foreach ($response->getHeaders() as $name => $values) {
			$replace = strtolower((string)$name) !== 'set-cookie';
			foreach ($values as $value) {
				header(sprintf('%s: %s', $name, $value), $replace);
				$replace = false;
			}
		}
	}

	private function emitBody(ResponseInterface $response): void
	{
		$body = $response->getBody();
		if ($body->isSeekable()) {
			$body->rewind();
		}

		while (!$body->eof()) {
			echo $body->read($this->responseChunkSize);
			if (connection_status() !== CONNECTION_NORMAL) {
				break;
			}
		}
	}

	private function isResponseEmpty(ResponseInterface $response): bool
	{
		if (in_array($response->getStatusCode(), [204, 205, 304])) {
			return true;
		}
		return $response->getBody()->getSize() === 0;
	}
}
