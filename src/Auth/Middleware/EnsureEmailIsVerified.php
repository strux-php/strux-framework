<?php

declare(strict_types=1);

namespace Strux\Auth\Middleware;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Strux\Auth\AuthManager;
use Strux\Support\Helpers\FlashInterface;

class EnsureEmailIsVerified implements MiddlewareInterface
{
	public function __construct(
		private AuthManager               $auth,
		private ResponseFactoryInterface  $responseFactory,
		private FlashInterface            $flash,
		private ?LoggerInterface          $logger = null,
		private ?string                   $verifyRoute = null
	) {}

	/**
	 * @throws JsonException
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$user = $this->auth->sentinel('web')->user();

		if (!$user) {
			$this->logger?->info("[EnsureEmailIsVerified] No authenticated user found. Redirecting to login.");
			return $this->redirectToLogin($request);
		}

		$isVerified = $this->userIsVerified($user);

		if ($isVerified) {
			$this->logger?->info("[EnsureEmailIsVerified] User email is verified. Proceeding.");
			return $handler->handle($request);
		}

		$this->logger?->info("[EnsureEmailIsVerified] User email is not verified. Redirecting to verification notice.");

		if ($request->getHeaderLine('Accept') === 'application/json') {
			$this->logger?->info("[EnsureEmailIsVerified] Returning 403 Forbidden for JSON request.");
			$response = $this->responseFactory->createResponse(403);
			$response->getBody()->write(json_encode([
				'error' => [
					'code' => 403,
					'type' => 'email_not_verified',
					'message' => 'Email not verified.',
				],
			], JSON_THROW_ON_ERROR));
			return $response->withHeader('Content-Type', 'application/json');
		}

		$this->flash->set('error', 'Please verify your email address to access this area.');

		$verifyUrl = $this->verifyRoute ?? '/email/verify';

		$this->logger?->info("[EnsureEmailIsVerified] Redirecting to: {$verifyUrl}");

		return $this->responseFactory->createResponse(302)
			->withHeader('Location', $verifyUrl);
	}

	private function userIsVerified(object $user): bool
	{
		if (method_exists($user, 'isVerified')) {
			return $user->isVerified();
		}

		return !(property_exists($user, 'email_verified_at') && $user->email_verified_at === null);
	}

	private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
	{
		if ($request->getHeaderLine('Accept') === 'application/json') {
			$response = $this->responseFactory->createResponse(401);
			$response->getBody()->write(json_encode([
				'error' => [
					'code' => 401,
					'type' => 'unauthorized',
					'message' => 'You must be logged in to access this resource.',
				],
			], JSON_THROW_ON_ERROR));
			return $response->withHeader('Content-Type', 'application/json');
		}

		$this->flash->set('error', 'You must be logged in to access this page.');

		return $this->responseFactory->createResponse(302)
			->withHeader('Location', '/login');
	}
}
