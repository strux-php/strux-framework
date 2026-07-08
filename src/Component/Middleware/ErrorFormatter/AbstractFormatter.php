<?php

declare(strict_types=1);

namespace Strux\Component\Middleware\ErrorFormatter;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Strux\Component\Exceptions\Container\ContainerException;
use Strux\Component\Exceptions\Container\NotFoundException;
use Strux\Component\Exceptions\CSRFMismatchException;
use Strux\Component\Exceptions\Http\HttpExceptionInterface;
use Strux\Component\Exceptions\Http\HttpMethodNotAllowedException;
use Strux\Component\Exceptions\RouteNotFoundException;
use Strux\Component\Exceptions\RouteParameterTypeMismatchException;
use Strux\Component\Exceptions\Http\UnsupportedMediaTypeHttpException;
use Strux\Component\Exceptions\ValidationException;
use Throwable;

abstract class AbstractFormatter implements FormatterInterface
{
	protected ResponseFactoryInterface $responseFactory;
	protected StreamFactoryInterface $streamFactory;
	protected bool $appDebug;

	/** @var string[] A list of MIME types this formatter can produce. */
	protected array $contentTypes = [];

	public function __construct(
		ResponseFactoryInterface $responseFactory,
		StreamFactoryInterface   $streamFactory,
		bool                     $appDebug = false
	) {
		$this->responseFactory = $responseFactory;
		$this->streamFactory = $streamFactory;
		$this->appDebug = $appDebug;
	}

	public function isValid(ServerRequestInterface $request): bool
	{
		$accept = $request->getHeaderLine('Accept');
		if (empty($accept) || $accept === '*/*') {
			return false;
		}

		foreach ($this->contentTypes as $type) {
			if (stripos($accept, $type) !== false) {
				return true;
			}
		}
		return false;
	}

	abstract protected function format(Throwable $error): string;

	public function handle(Throwable $error, ServerRequestInterface $request): ResponseInterface
	{
		$contentType = $this->contentTypes[0] ?? 'text/plain';
		$code = $this->determineStatusCode($error);
		$response = $this->responseFactory
			->createResponse($code)
			->withHeader('Content-Type', $contentType . '; charset=utf-8');
		if ($error instanceof HttpMethodNotAllowedException && !empty($error->getAllowedMethods())) {
			$response = $response->withHeader('Allow', implode(', ', $error->getAllowedMethods()));
		}
		$bodyContent = $this->format($error);
		$bodyStream = $this->streamFactory->createStream($bodyContent);
		return $response->withBody($bodyStream);
	}

	protected function determineStatusCode(Throwable $error): int
	{
		if ($error instanceof HttpExceptionInterface) {
			return $error->getStatusCode();
		}
		if ($error instanceof RouteNotFoundException) {
			return 404;
		}
		if ($error instanceof HttpMethodNotAllowedException) {
			return 405;
		}
		if ($error instanceof UnsupportedMediaTypeHttpException) {
			return 415;
		}
		if ($error instanceof ValidationException) {
			return 422;
		}
		if ($error instanceof CSRFMismatchException) {
			return 419;
		}
		if ($error instanceof RouteParameterTypeMismatchException) {
			return 400;
		}
		if ($error instanceof ContainerException || $error instanceof NotFoundException) {
			return 400;
		}

		$code = $error->getCode();
		if ($code >= 400 && $code < 600) {
			return $code;
		}

		return 500;
	}
}
