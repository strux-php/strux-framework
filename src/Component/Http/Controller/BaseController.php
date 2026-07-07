<?php

declare(strict_types=1);

namespace Strux\Component\Http\Controller;

use InvalidArgumentException;
use PDO;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Strux\Auth\AuthManager;
use Strux\Auth\AuthProxy;
use Strux\Component\Exceptions\AuthorizationException;
use Strux\Component\Exceptions\Container\ContainerException;
use Strux\Component\Http\Request;
use Strux\Component\Http\Response;
use Strux\Component\Routing\Router;
use Strux\Component\Session\SessionInterface;
use Strux\Component\View\ViewInterface;
use Strux\Support\ContainerBridge;
use Strux\Support\Helpers\FlashInterface;
use Strux\Component\Form\FormFactory;

/**
 * Class BaseController
 */
abstract class BaseController
{
	protected ?ContainerInterface $container = null;
	protected ?Request $request = null;
	protected ?PDO $db = null;
	protected ?SessionInterface $session = null;
	protected ?LoggerInterface $logger = null;
	protected ?ViewInterface $view = null;
	protected ?EventDispatcherInterface $event = null;
	protected ?FlashInterface $flash = null;
	protected ?ResponseFactoryInterface $responseFactory = null;
	protected ?AuthManager $authManager = null;
	protected AuthProxy $auth;
	protected ?FormFactory $forms = null;

	/**
	 * @throws ContainerExceptionInterface
	 * @throws ContainerException
	 * @throws NotFoundExceptionInterface
	 */
	public function __construct(
		?ContainerInterface $container = null,
		?Request $request = null,
		?ResponseFactoryInterface $responseFactory = null,
		?PDO $db = null,
		?SessionInterface $session = null,
		?LoggerInterface $logger = null,
		?ViewInterface $view = null,
		?EventDispatcherInterface $event = null,
		?FlashInterface $flash = null,
		?AuthManager $authManager = null,
		?FormFactory $forms = null
	) {
		$this->container = $container ?? ContainerBridge::getContainer();

		$this->request = $request ?? ($this->container->has(Request::class)
			? $this->container->get(Request::class)
			: ContainerBridge::resolve(Request::class)
		);
		$this->responseFactory = $responseFactory ?? ($this->container->has(ResponseFactoryInterface::class)
			? $this->container->get(ResponseFactoryInterface::class)
			: ContainerBridge::resolve(ResponseFactoryInterface::class)
		);
		$this->db = $db ?? ($this->container->has(PDO::class)
			? $this->container->get(PDO::class)
			: ContainerBridge::resolve(PDO::class)
		);

		$this->session = $session ?? ($this->container->has(SessionInterface::class)
			? $this->container->get(SessionInterface::class)
			: ContainerBridge::resolve(SessionInterface::class)
		);
		$this->logger = $logger ?? ($this->container->has(LoggerInterface::class)
			? $this->container->get(LoggerInterface::class)
			: ContainerBridge::resolve(LoggerInterface::class)
		);
		$this->view = $view ?? ($this->container->has(ViewInterface::class)
			? $this->container->get(ViewInterface::class)
			: ContainerBridge::resolve(ViewInterface::class)
		);
		$this->event = $event ?? ($this->container->has(EventDispatcherInterface::class)
			? $this->container->get(EventDispatcherInterface::class)
			: ContainerBridge::resolve(EventDispatcherInterface::class)
		);
		$this->flash = $flash ?? ($this->container->has(FlashInterface::class)
			? $this->container->get(FlashInterface::class)
			: ContainerBridge::resolve(FlashInterface::class)
		);
		$this->authManager = $authManager ?? ($this->container->has(AuthManager::class)
			? $this->container->get(AuthManager::class)
			: ContainerBridge::resolve(AuthManager::class)
		);
		$this->auth = new AuthProxy($this->authManager);
		$this->forms = $forms ?? ($this->container->has(FormFactory::class)
			? $this->container->get(FormFactory::class)
			: ContainerBridge::resolve(FormFactory::class)
		);
	}

	/**
	 * Creates a new Strux\Component\Http\Response object.
	 */
	protected function createResponse(string $content = '', int $status = 200, array $headers = []): Response
	{
		return new Response($content, $status, $headers);
	}

	/**
	 * Helper to create a JSON response using Strux\Component\Http\Response.
	 */
	protected function json(mixed $data, int $status = 200, array $headers = [], int $encodingOptions = 0): Response
	{
		$response = $this->createResponse('', $status, $headers);
		return $response->json($data, $status, $headers, $encodingOptions);
	}

	/**
	 * Helper to create a redirect response using Strux\Component\Http\Response.
	 */
	protected function redirect(string $uri, int $status = 302): Response
	{
		$response = $this->createResponse('', $status);
		return $response->redirect($uri, $status);
	}

	/**
	 * Helper to create a redirect response with flashed messages.
	 */
	protected function redirectWith(string $uri, array $messages = [], int $status = 302, bool $isRouteName = false, array $routeParams = []): Response
	{
		if ($this->flash) {
			foreach ($messages as $type => $message) {
				$this->flash->set((string) $type, $message);
			}
		} elseif (!empty($messages)) {
			$this->logWarning("FlashService not available, cannot flash messages for redirect.", ['messages' => $messages]);
		}

		$targetUri = $uri;
		if ($isRouteName) {
			try {
				$targetUri = $this->route($uri, $routeParams);
			} catch (RuntimeException $e) {
				$this->logError("Failed to generate route for redirectWith: " . $e->getMessage(), ['route_name' => $uri]);
				$targetUri = '/';
			}
		}
		return $this->redirect($targetUri, $status);
	}

	/**
	 * Helper to generate a URL for a named route.
	 */
	protected function route(string $routeName, array $data = [], array $queryParams = []): string
	{
		if (!$this->container->has(Router::class)) {
			$this->logError("Router not found in container. Cannot generate route for '$routeName'.");
			throw new RuntimeException("Router service not available for URL generation.");
		}
		try {
			/** @var Router $router */
			$router = $this->container->get(Router::class);

			/* if (str_contains($routeName, '/')) {
                $routeName = str_replace('/', '.', trim($routeName, '/' ?? '')); // Convert to dot notation
            } */
			// TODO: Convert to dot notation, if the route is not found, find the route by path
			// This is useful for routes defined in the routes file with slashes, e.g., 'admin/users/create'.
			if (str_contains($routeName, '/')) {
				$routeName = str_replace('/', '.', trim($routeName, '/'));
			}
			return $router->route($routeName, array_merge($data, $queryParams));
		} catch (InvalidArgumentException $e) {
			$this->logError("Error generating route '$routeName': " . $e->getMessage(), ['data' => $data, 'queryParams' => $queryParams]);
			throw new RuntimeException("Failed to generate URL for route '$routeName': " . $e->getMessage(), 0, $e);
		} catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
			$this->logError("Router service not found or error in route generation: " . $e->getMessage(), ['route_name' => $routeName, 'data' => $data, 'queryParams' => $queryParams]);
			throw new RuntimeException("Router service not available or route '$routeName' not found.");
		}
	}

	protected function toRoute(string $routeName, array $parameters = [], array $flashMessages = [], int $status = 302): Response
	{
		return $this->redirectWith(
			uri: $routeName,
			messages: $flashMessages,
			status: $status,
			isRouteName: true,
			routeParams: $parameters
		);
	}

	/**
	 * Authorize a given action against a set of arguments.
	 * @throws AuthorizationException
	 */
	protected function authorize(string $ability, mixed $arguments = []): void
	{
		if ($this->authManager->cannot($ability, $arguments)) {
			throw new AuthorizationException('You are not authorized to perform this action.', 403);
		}
	}

	/**
	 * Renders a view and returns an Strux\Component\Http\Response.
	 */
	protected function view(string $templateName, array $data = [], int $status = 200): Response
	{
		if (!$this->view) {
			$this->logError("View engine (ViewInterface) not available. Cannot render '$templateName'.");
			return $this->createResponse("Error: View engine not configured.", 500);
		}

		$content = $this->view->render($templateName, $data);

		return $this->createResponse($content, $status, ['Content-Type' => 'text/html; charset=utf-8']);
	}

	protected function logError(string $message, array $context = []): void
	{
		if ($this->logger) {
			$this->logger->error("[" . static::class . "] " . $message, $context);
		} else {
			error_log("[" . static::class . " Error] " . $message . (!empty($context) ? " Context: " . json_encode($context) : ""));
		}
	}

	protected function logWarning(string $message, array $context = []): void
	{
		if ($this->logger) {
			$this->logger->warning("[" . static::class . "] " . $message, $context);
		} else {
			error_log("[" . static::class . " Warning] " . $message . (!empty($context) ? " Context: " . json_encode($context) : ""));
		}
	}

	protected function logInfo(string $message, array $context = []): void
	{
		if ($this->logger) {
			$this->logger->info("[" . static::class . "] " . $message, $context);
		}
	}
}
