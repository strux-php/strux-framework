<?php

declare(strict_types=1);

namespace Strux\Auth\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Strux\Auth\AuthManager;
use Strux\Component\Config\Config;
use Strux\Support\Helpers\FlashInterface;

class GuestMiddleware implements MiddlewareInterface
{
	public function __construct(
		private AuthManager $authManager,
		private ResponseFactoryInterface $responseFactory,
		private FlashInterface $flash,
		private Config $config
	) {}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler
	): ResponseInterface {
		$user = $this->authManager->sentinel('web')->user();

		if ($user) {
			$resolvedRedirect = $this->authManager->redirectFor($user);

			$queryParams = $request->getQueryParams();
			$next = $queryParams['next'] ?? $resolvedRedirect;

			$this->flash->set('success', 'Logged in successfully. Welcome back!');

			return $this->responseFactory->createResponse(302)
				->withHeader('Location', $next);
		}

		return $handler->handle($request);
	}
}
