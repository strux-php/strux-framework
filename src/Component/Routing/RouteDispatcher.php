<?php

declare(strict_types=1);

namespace Strux\Component\Routing;

use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;
use Strux\Component\Http\Attributes\Consumes;
use Strux\Component\Http\Attributes\Produces;
use Strux\Component\Http\Attributes\ResponseHeader;
use Strux\Component\Exceptions\Http\HttpMethodNotAllowedException;
use Strux\Component\Exceptions\RouteNotFoundException;
use Strux\Component\Exceptions\Http\UnsupportedMediaTypeHttpException;
use Strux\Component\Http\ApiResponse;
use Strux\Component\Http\Middleware\Dispatcher as MiddlewareDispatcher;
use Strux\Component\Http\Response;
use Strux\Foundation\Container;

class RouteDispatcher implements RequestHandlerInterface
{
	/** @var Container $container */
	private ContainerInterface $container;
	private Router $router;
	private ParameterResolver $parameterResolver;

	public function __construct(
		ContainerInterface $container,
		Router $router,
		ParameterResolver $parameterResolver
	) {
		$this->container = $container;
		$this->router = $router;
		$this->parameterResolver = $parameterResolver;
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws RouteNotFoundException
	 * @throws ContainerExceptionInterface
	 * @throws HttpMethodNotAllowedException
	 * @throws InvalidArgumentException
	 */
	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		$routeInfo = $this->router->dispatch(
			$request->getMethod(),
			$request->getUri()->getPath()
		);

		if (isset($routeInfo['type']) && $routeInfo['type'] === 'redirect') {
			/** @var ResponseFactoryInterface $responseFactory */
			$responseFactory = $this->container->get(ResponseFactoryInterface::class);
			return $responseFactory
				->createResponse($routeInfo['status_code'])
				->withHeader('Location', (string)$routeInfo['target']);
		}

		if (!isset($routeInfo['type']) || $routeInfo['type'] !== 'handler') {
			throw new LogicException(
				"Router dispatch returned an unknown or invalid route type: " . ($routeInfo['type'] ?? 'undefined')
			);
		}

		$request = $request->withAttribute('route', $routeInfo);

		foreach ($routeInfo['parameters'] as $key => $value) {
			$request = $request->withAttribute($key, $value);
		}

		if (isset($routeInfo['controller']) && isset($routeInfo['method'])) {
			$handler = $routeInfo['controller'];
			$method = $routeInfo['method'];

			if (is_array($handler)) {
				$request = $request->withAttribute('handler', $handler);
			} elseif (is_string($handler)) {
				$request = $request->withAttribute('handler', [$handler, $method]);
			}
		}

		$this->container->bind(ServerRequestInterface::class, $request);
		$this->container->bind($request::class, $request);

		$cacheTtl = $routeInfo['extra']['cache_ttl'] ?? null;
		/** @var CacheInterface $cacheService */
		$cacheService = $this->container->get(CacheInterface::class);

		if ($cacheTtl > 0 && in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'])) {
			$cacheKey = 'route_cache_' . md5($request->getUri()->__toString());

			if ($cacheService->has($cacheKey)) {
				$cachedData = $cacheService->get($cacheKey);
				if (is_array($cachedData) && isset($cachedData['body']) && isset($cachedData['headers'])) {
					/** @var ResponseFactoryInterface $responseFactory */
					$responseFactory = $this->container->get(ResponseFactoryInterface::class);
					$response = $responseFactory->createResponse(200);
					$response->getBody()->write($cachedData['body']);
					foreach ($cachedData['headers'] as $name => $value) {
						$response = $response->withHeader($name, $value);
					}
					return $response->withHeader('X-Cache-Status', 'hit');
				}
			}
		}

		$controllerActionHandler = new class($this->container, $this->parameterResolver, $routeInfo) implements RequestHandlerInterface {
			/** @var Container $container */
			private ContainerInterface $container;
			private ParameterResolver $parameterResolver;
			private array $routeInfo;

			public function __construct(ContainerInterface $c, ParameterResolver $pr, array $ri)
			{
				$this->container = $c;
				$this->parameterResolver = $pr;
				$this->routeInfo = $ri;
			}

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				/** @var ?Consumes $consumesAttr */
				$consumesAttr = $this->routeInfo['extra']['consumes'] ?? null;
				$requestMethod = strtoupper($request->getMethod());

				if ($consumesAttr && in_array($requestMethod, ['POST', 'PUT', 'PATCH'])) {
					$contentType = $request->getHeaderLine('Content-Type');

					if (stripos($contentType, $consumesAttr->mediaType) === false) {
						throw new UnsupportedMediaTypeHttpException(
							"The request Content-Type '$contentType' is not supported. This endpoint consumes '$consumesAttr->mediaType'."
						);
					}
				}

				$this->container->bind(ServerRequestInterface::class, $request);
				$this->container->bind($request::class, $request);

				$handlerDefinition = $this->routeInfo['controller'];
				$methodName = $this->routeInfo['method'];

				if (is_callable($handlerDefinition) && !is_array($handlerDefinition)) {
					$reflectionCallable = new ReflectionFunction($handlerDefinition);
					$args = $this->parameterResolver->resolve($reflectionCallable->getParameters(), $request);
					$finalResponse = call_user_func_array($handlerDefinition, $args);
				} elseif (is_string($handlerDefinition) && class_exists($handlerDefinition) && $methodName !== null) {
					$controllerInstance = $this->container->get($handlerDefinition);
					$reflectionMethod = new ReflectionMethod($controllerInstance, $methodName);
					$args = $this->parameterResolver->resolve($reflectionMethod->getParameters(), $request);
					$finalResponse = call_user_func_array([$controllerInstance, $methodName], $args);
				} else {
					throw new LogicException("Invalid route handler configuration.");
				}

				$defaultStatus = $this->routeInfo['extra']['status'] ?? null;
				if ($defaultStatus && $finalResponse instanceof ApiResponse) {
					$finalResponse->setStatusCode($defaultStatus);
				}

				/** @var ?Produces $producesAttr */
				$producesAttr = $this->routeInfo['extra']['produces'] ?? null;
				if ($producesAttr && $finalResponse instanceof ApiResponse) {
					$finalResponse->setHeader('Content-Type', $producesAttr->mediaType);
				}

				/** @var array<ResponseHeader> $extraHeaders */
				$extraHeaders = $this->routeInfo['extra']['headers'] ?? [];
				if (!empty($extraHeaders) && $finalResponse instanceof Response) {
					foreach ($extraHeaders as $headerAttribute) {
						$finalResponse->setHeader($headerAttribute->key, $headerAttribute->value);
					}
				}

				if ($finalResponse instanceof Response) {
					return $finalResponse->toPsr7Response(
						$this->container->get(StreamFactoryInterface::class)
					);
				}

				if ($finalResponse instanceof ResponseInterface) {
					return $finalResponse;
				}
				throw new LogicException("Controller action did not return a valid ResponseInterface object.");
			}
		};

		$middlewareDispatcher = new MiddlewareDispatcher($routeInfo['middleware'] ?? [], $this->container);
		$response = $middlewareDispatcher->dispatch($request, $controllerActionHandler);

		if (isset($cacheTtl) && $cacheTtl > 0 && in_array(strtoupper($request->getMethod()), ['GET', 'HEAD']) && $response->getStatusCode() === 200) {
			$response = $response->withHeader('Cache-Control', "web, max-age=$cacheTtl");

			$cacheKey = 'route_cache_' . md5($request->getUri()->__toString());

			$cacheableData = [
				'body' => (string)$response->getBody(),
				'headers' => $response->getHeaders(),
			];

			$cacheService->set($cacheKey, $cacheableData, $cacheTtl);
			return $response->withHeader('X-Cache-Status', 'miss');
		}

		return $response;
	}
}
