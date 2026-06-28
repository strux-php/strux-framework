<?php

declare(strict_types=1);

namespace Strux\Component\Routing;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Strux\Component\Database\ORM\Model;
use Strux\Component\Exceptions\Http\NotFoundHttpException;
use Strux\Component\Http\Attributes\RequestBody;
use Strux\Component\Http\Attributes\RequestParam;
use Strux\Component\Http\Attributes\RequestQuery;
use Strux\Component\Exceptions\ValidationException;
use Strux\Component\Routing\Attributes\RouteEntity;
use Strux\Component\Http\Request as AppRequest;
use Strux\Component\Http\Request\FormRequest;

class ParameterResolver
{
	public function __construct(
		private ContainerInterface $container
	) {}

	/**
	 * Resolves the arguments for a given set of reflection parameters.
	 *
	 * @param ReflectionParameter[] $reflectionParams
	 * @param ServerRequestInterface $request
	 * @return array The resolved arguments ready to be passed to the method/closure.
	 * @throws ValidationException
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function resolve(array $reflectionParams, ServerRequestInterface $request): array
	{
		$args = [];
		$routeParams = $request->getAttributes();
		$appRequest = new AppRequest($request);

		foreach ($reflectionParams as $param) {
			$type = $param->getType();
			$name = $param->getName();

			$paramAttributes = $param->getAttributes();

			$isRequestBody = false;
			$isRequestQuery = false;

			foreach ($paramAttributes as $attribute) {
				if ($attribute->getName() === RequestBody::class) {
					$isRequestBody = true;
					break;
				}
				if ($attribute->getName() === RequestQuery::class) {
					$isRequestQuery = true;
					break;
				}
			}

			if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
				$typeName = $type->getName();

				if ($isRequestBody || $isRequestQuery) {
					if (is_subclass_of($typeName, FormRequest::class)) {
						$formRequest = new $typeName();
						$dataToValidate = $isRequestBody
							? (json_decode($request->getBody()->getContents(), true) ?? [])
							: $request->getQueryParams();

						$formRequest->populateAndValidate($dataToValidate);
						$args[] = $formRequest;
						continue;
					}
				}

				if ($typeName === AppRequest::class) {
					$args[] = $appRequest;
					continue;
				}
				if ($typeName === ServerRequestInterface::class) {
					$args[] = $request;
					continue;
				}

				// #[RouteEntity] attribute handling for custom route model binding
				$routeEntityAttr = null;
				foreach ($paramAttributes as $attribute) {
					if ($attribute->getName() === RouteEntity::class) {
						$routeEntityAttr = $attribute->newInstance();
						break;
					}
				}

				if ($routeEntityAttr !== null) {
					$modelClass = $typeName;

					if (!is_subclass_of($modelClass, Model::class)) {
						if ($this->container->has($modelClass)) {
							$instance = $this->container->get($modelClass);
							$modelClass = get_class($instance);
						}
						if (!is_subclass_of($modelClass, Model::class)) {
							throw new RuntimeException(
								sprintf('#[RouteEntity] on parameter "$%s" requires a Model subclass, got %s', $name, $typeName)
							);
						}
					}

					$query = $modelClass::query();
					foreach ($routeEntityAttr->mapping as $routeParam => $column) {
						if (!array_key_exists($routeParam, $routeParams)) {
							throw new RuntimeException(
								sprintf('Route parameter "%s" not found for #[RouteEntity] mapping on "$%s"', $routeParam, $name)
							);
						}
						$query->where($column, $routeParams[$routeParam]);
					}

					if (!empty($routeEntityAttr->with)) {
						$query->with(...$routeEntityAttr->with);
					}

					$model = $query->first();

					if ($model !== null) {
						$args[] = $model;
						continue;
					}

					throw new NotFoundHttpException(
						sprintf('%s not found for #[RouteEntity] on parameter "$%s"', $modelClass, $name)
					);
				}

				// Route model binding: resolve Model subclasses from route parameters
				// Runs BEFORE the container check to prevent auto-wiring from returning an empty instance
				if (is_subclass_of($typeName, Model::class)) {
					$routeKey = array_key_exists($name, $routeParams) ? $name : 'id';

					if (array_key_exists($routeKey, $routeParams)) {
						$model = $typeName::find($routeParams[$routeKey]);

						if ($model !== null) {
							$args[] = $model;
							continue;
						}

						throw new NotFoundHttpException(
							"{$typeName} not found with {$routeKey}: {$routeParams[$routeKey]}"
						);
					}
				}

				// Handle any other service registered in the container
				if ($this->container->has($typeName)) {
					$args[] = $this->container->get($typeName);
					continue;
				}
			}

			$requestParamAttr = $param->getAttributes(RequestParam::class)[0] ?? null;

			if ($requestParamAttr) {
				$instance = $requestParamAttr->newInstance();
				$routeParamName = $instance->name ?? $name;
				if (array_key_exists($routeParamName, $routeParams)) {
					$args[] = $routeParams[$routeParamName];
					continue;
				}
			}

			if (array_key_exists($name, $routeParams)) {
				$args[] = $routeParams[$name];
				continue;
			}

			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}

			if ($param->allowsNull()) {
				$args[] = null;
				continue;
			}

			throw new RuntimeException("Could not resolve parameter '$name' for route handler.");
		}
		return $args;
	}
}
