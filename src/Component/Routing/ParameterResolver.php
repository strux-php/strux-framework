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
use Strux\Component\Http\Attributes\RequestBody;
use Strux\Component\Http\Attributes\RequestParam;
use Strux\Component\Http\Attributes\RequestQuery;
use Strux\Component\Exceptions\ValidationException;
use Strux\Component\Http\Request as AppRequest;
use Strux\Component\Http\Request\FormRequest;

class ParameterResolver
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

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

            // First, try to resolve by type-hint from the DI container.
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

                // Handle specific framework request objects
                if ($typeName === AppRequest::class) {
                    $args[] = $appRequest;
                    continue;
                }
                if ($typeName === ServerRequestInterface::class) {
                    $args[] = $request;
                    continue;
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
                $routeParamName = $instance->name ?? $name; // Use attribute name or fall back to arg name
                if (array_key_exists($routeParamName, $routeParams)) {
                    $args[] = $routeParams[$routeParamName];
                    continue;
                }
            }

            // If not resolved by type, try to resolve by name from the route's URL parameters.
            if (array_key_exists($name, $routeParams)) {
                $args[] = $routeParams[$name];
                continue;
            }

            // If not found, check if the parameter has a default value.
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // If the parameter is explicitly nullable, provide null.
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            // If we've reached here, we cannot resolve the parameter.
            throw new RuntimeException("Could not resolve parameter '$name' for route handler.");
        }
        return $args;
    }
}
