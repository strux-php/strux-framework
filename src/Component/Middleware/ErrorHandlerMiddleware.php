<?php

declare(strict_types=1);

namespace Strux\Component\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Strux\Component\Middleware\ErrorFormatter\FormatterInterface;
use Throwable;

/**
 * PSR-15 Error Handling Middleware.
 * This should be the first middleware in your application stack.
 */
class ErrorHandlerMiddleware implements MiddlewareInterface
{
	/** @var FormatterInterface[] */
	private array $formatters;
	private ?LoggerInterface $logger;
	private FormatterInterface $defaultFormatter;

	/**
	 * @param FormatterInterface[] $formatters An array of available formatters (e.g., HtmlFormatter, JsonFormatter).
	 * @param FormatterInterface $defaultFormatter The formatter to use if content negotiation fails.
	 * @param LoggerInterface|null $logger To log all Throwable.
	 */
	public function __construct(
		array              $formatters,
		FormatterInterface $defaultFormatter,
		?LoggerInterface   $logger = null
	) {
		$this->formatters = $formatters;
		$this->defaultFormatter = $defaultFormatter;
		$this->logger = $logger;
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		try {
			return $handler->handle($request);
		} catch (Throwable $e) {

			$context = [
				'exception' => get_class($e),
				'message' => $e->getMessage(),
				'code' => $e->getCode(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'request_target' => $request->getRequestTarget(),
				'method' => $request->getMethod()
			];
			$this->logger->error("Caught Unhandled Throwable: {$e->getMessage()}", $context);

			foreach ($this->formatters as $formatter) {
				if ($formatter->isValid($request)) {
					return $formatter->handle($e, $request);
				}
			}

			return $this->defaultFormatter->handle($e, $request);
		}
	}
}
