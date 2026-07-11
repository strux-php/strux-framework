<?php

declare(strict_types=1);

namespace Strux\Component\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class RateLimitingMiddleware implements MiddlewareInterface
{
	private CacheInterface $cache;
	private ?LoggerInterface $logger;
	private int $maxAttempts;
	private int $decaySeconds;
	private string $keyPrefix;

	public function __construct(
		CacheInterface            $cache,
		?LoggerInterface         $logger = null,
		array                     $config = []
	) {
		$this->cache = $cache;
		$this->logger = $logger;
		$this->maxAttempts = (int) ($config['max_attempts'] ?? 60);
		$this->decaySeconds = (int) ($config['decay_seconds'] ?? 60);
		$this->keyPrefix = $config['key_prefix'] ?? 'rate_limit_';
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
		$window = (int) floor(time() / $this->decaySeconds);
		$cacheKey = $this->keyPrefix . $ip . ':' . $window;

		try {
			$current = $this->cache->get($cacheKey, 0);
			$current++;
			$this->cache->set($cacheKey, $current, $this->decaySeconds);
		} catch (\Throwable $e) {
			$this->logger?->warning('[RateLimitingMiddleware] Cache error: ' . $e->getMessage());
			return $handler->handle($request);
		}

		$maxAttempts = $this->maxAttempts;
		$remaining = max(0, $maxAttempts - $current);
		$resetAt = ($window + 1) * $this->decaySeconds;

		$request = $request
			->withAttribute('rate_limit_max', $maxAttempts)
			->withAttribute('rate_limit_remaining', $remaining)
			->withAttribute('rate_limit_reset', $resetAt);

		if ($current > $maxAttempts) {
			$this->logger?->warning('[RateLimitingMiddleware] Rate limit exceeded.', [
				'ip' => $ip,
				'attempts' => $current,
				'max' => $maxAttempts,
			]);

			$responseFactory = $this->getResponseFactory($request);

			$retryAfter = $resetAt - time();
			$response = $responseFactory->createResponse(429)
				->withHeader('Content-Type', 'application/json')
				->withHeader('Retry-After', (string) $retryAfter)
				->withHeader('X-RateLimit-Limit', (string) $maxAttempts)
				->withHeader('X-RateLimit-Remaining', '0')
				->withHeader('X-RateLimit-Reset', (string) $resetAt);

			$body = $response->getBody();
			$body->write(json_encode([
				'error' => 'Too Many Requests',
				'message' => 'Rate limit exceeded. Please try again later.',
				'retry_after' => $retryAfter,
			], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

			return $response;
		}

		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-Limit', (string) $maxAttempts)
			->withHeader('X-RateLimit-Remaining', (string) $remaining)
			->withHeader('X-RateLimit-Reset', (string) $resetAt);
	}

	private function getResponseFactory(ServerRequestInterface $request): ResponseFactoryInterface
	{
		$serverParams = $request->getServerParams();
		$factoryClass = $serverParams['response_factory'] ?? null;

		if ($factoryClass && class_exists($factoryClass)) {
			return new $factoryClass();
		}

		return new class implements ResponseFactoryInterface {
			public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
			{
				return new \Strux\Component\Http\Psr7\Response($code);
			}
		};
	}
}
