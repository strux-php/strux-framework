<?php

declare(strict_types=1);

namespace Strux\Component\Middleware;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Strux\Component\Exceptions\CSRFMismatchException;
use Strux\Component\Session\SessionInterface;

class CsrfProtectionMiddleware implements MiddlewareInterface
{
	private SessionInterface $session;
	private ?LoggerInterface $logger;
	private array $except;
	private string $sessionKey = '_csrf_token';
	private string $formFieldName = '_csrf_token';
	private string $headerName = 'X-CSRF-Token';

	public function __construct(
		SessionInterface $session,
		?LoggerInterface $logger = null,
		array            $config = []
	) {
		$this->session = $session;
		$this->logger = $logger;

		$this->except = $config['except'] ?? [];
	}

	/**
	 * @inheritDoc
	 * @throws Exception
	 * @throws CSRFMismatchException
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->isExcluded($request)) {
			$this->logger?->info('[CsrfProtectionMiddleware] Request URI is excluded from CSRF protection.', [
				'uri' => $request->getUri()->getPath()
			]);
			return $handler->handle($request);
		}

		$this->logger->log('info', '[CsrfProtectionMiddleware] Processing CSRF protection middleware', [
			'method' => $request->getMethod(),
			'uri' => (string)$request->getUri()
		]);

		//if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'])) {
		$token = $this->session->get($this->sessionKey);
		if (!$token) {
			$token = $this->generateToken();
			$this->session->set($this->sessionKey, $token);
		}

		$request = $request->withAttribute('csrf_token', $token);
		$request = $request->withAttribute('csrf_field_name', $this->formFieldName);

		$this->logger?->info('Post info.', [
			'post' => $request
		]);
		// dump($request);
		//}

		if (in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
			$submittedToken = $this->getSubmittedToken($request);
			$sessionToken = $this->session->get($this->sessionKey);
			$this->logger?->warning('CSRF request data.', [
				'submittedToken' => $submittedToken,
				'sessionToken' => $sessionToken
			]);

			if (!$sessionToken || !$submittedToken || !$this->verifyToken($submittedToken, $sessionToken)) {
				$this->logger?->warning('CSRF token validation failed.', [
					'method' => $request->getMethod(),
					'uri' => (string)$request->getUri(),
					'ip_address' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
				]);

				throw new CSRFMismatchException(
					"CSRF token mismatch. Please refresh and try again.",
					419
				);
			}
		}

		return $handler->handle($request);
	}

	/**
	 * Determine if the request URI should be excluded from CSRF verification.
	 */
	private function isExcluded(ServerRequestInterface $request): bool
	{
		$path = trim($request->getUri()->getPath(), '/');

		foreach ($this->except as $exceptPath) {
			$exceptPath = trim($exceptPath, '/');
			if ($exceptPath === $path) {
				return true;
			}
			if (str_ends_with($exceptPath, '*')) {
				$prefix = rtrim($exceptPath, '*');
				if (str_starts_with($path, $prefix)) {
					return true;
				}
			}
		}

		return false;
	}

	private function getSubmittedToken(ServerRequestInterface $request): ?string
	{
		$parsedBody = $request->getParsedBody();
		if (is_array($parsedBody) && !empty($parsedBody[$this->formFieldName])) {
			return (string)$parsedBody[$this->formFieldName];
		}

		if ($request->hasHeader($this->headerName)) {
			return $request->getHeaderLine($this->headerName);
		}

		return null;
	}

	/**
	 * @throws Exception
	 */
	private function generateToken(): string
	{
		$randomBytes = random_bytes(32);
		$base64String = base64_encode($randomBytes);
		return rtrim(strtr($base64String, '+/', '-_'), '=');
	}

	private function verifyToken(string $submittedToken, string $sessionToken): bool
	{
		return hash_equals($sessionToken, $submittedToken);
	}
}
