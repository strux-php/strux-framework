<?php

declare(strict_types=1);

namespace Strux\Component\Routing;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Strux\Component\Exceptions\Http\HttpMethodNotAllowedException;
use Strux\Component\Exceptions\RouteNotFoundException;
use Strux\Component\Exceptions\RouteParameterTypeMismatchException;

class Router
{
    protected array $routes = [];
    protected array $namedRoutes = [];
    protected array $redirectRoutes = [];
    private string $currentGroupPrefix = '';
    private array $currentGroupMiddleware = [];
    private array $currentGroupDefaults = [];

    private ?ServerRequestInterface $currentRequest;
    private array $lastAddedRouteKeys = [];
    // private ?int $lastAddedRouteKey = null;
    // private ?int $lastAddedRedirectRouteKey = null;

    private ?array $currentRoute = null;

    public const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
    private const PARAM_TYPES_REGEX_VALIDATORS = [
        'int' => '/^\d+$/',
        'string' => '/^[a-zA-Z0-9_.-]+$/',
        'slug' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        'alpha' => '/^[a-zA-Z]+$/',
        'alnum' => '/^[a-zA-Z0-9]+$/',
        '*' => '/^.+$/',
    ];

    private const GENERIC_SEGMENT_CAPTURE_REGEX = '[^/]+';
    private const WILDCARD_SEGMENT_CAPTURE_REGEX = '.+';

    private const SEGMENT_PLACEHOLDER_CORE_PATTERN =
    '/^(?:' .
        '(?P<wildcard_def>\*\:(?P<wildcard_name>[a-zA-Z_][a-zA-Z0-9_-]+))' . // *:name
        '|' .
        '(?P<typed_def>(?P<type>[a-zA-Z]+)\:(?P<typed_param_name>[a-zA-Z_][a-zA-Z0-9_-]+))' . // type:name
        '|' .
        '(?P<untyped_def>\:(?P<untyped_param_name>[a-zA-Z_][a-zA-Z0-9_-]+))' . // :name
        ')(?P<optional_marker>\|\?)?$/';


    public function __construct(
        ?ServerRequestInterface $currentRequest = null
    ) {
        $this->currentRequest = $currentRequest;
    }

    /**
     * Compiles a URI pattern into a regex and extracts parameter definitions.
     * Parameter definitions will include name, type (if any), and optionality.
     * The generated regex will use a generic capture for parameters,
     * and type validation will happen after matching.
     */
    private function compileUriToRegex(string $uri, array &$paramDefinitions): string
    {
        $paramDefinitions = [];
        $regex = '';
        $trimmedUri = trim($uri, '/');

        if ($trimmedUri === '') {
            return '#^/$#u';
        }

        $segments = explode('/', $trimmedUri);

        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT_PLACEHOLDER_CORE_PATTERN, $segment, $matches)) {
                $currentParamName = null;
                $currentParamType = null;
                $isOptional = !empty($matches['optional_marker']);
                $captureRegex = self::GENERIC_SEGMENT_CAPTURE_REGEX;

                if (!empty($matches['wildcard_def'])) {
                    $currentParamName = $matches['wildcard_name'];
                    $currentParamType = '*';
                    $captureRegex = self::WILDCARD_SEGMENT_CAPTURE_REGEX;
                } elseif (!empty($matches['typed_def'])) {
                    $currentParamName = $matches['typed_param_name'];
                    $currentParamType = $matches['type'];
                    // Capture regex remains generic for matching, type used for validation later
                } elseif (!empty($matches['untyped_def'])) {
                    $currentParamName = $matches['untyped_param_name'];
                    // $currentParamType remains null (untyped)
                }

                if ($currentParamName) {
                    $paramDefinitions[] = [
                        'name' => $currentParamName,
                        'type' => $currentParamType, // Store the declared type
                        'optional' => $isOptional,
                        // 'validator_regex' => $currentParamType ? (self::PARAM_TYPES_REGEX_VALIDATORS[$currentParamType] ?? null) : null
                    ];
                    $parameterGroup = '(?P<' . $currentParamName . '>' . $captureRegex . ')';

                    if ($isOptional) {
                        $regex .= '(?:/' . $parameterGroup . ')?';
                    } else {
                        $regex .= '/' . $parameterGroup;
                    }
                } else {
                    // Should not happen if pattern is robust
                    $regex .= '/' . preg_quote($segment, '#');
                }
            } else {
                $regex .= '/' . preg_quote($segment, '#');
            }
        }
        return "#^{$regex}$#u";
    }

    public function addRouteDefinition(array|string $httpMethods, string $uri, mixed $handler): self
    {
        // $this->lastAddedRedirectRouteKey = null;
        $this->lastAddedRouteKeys = [];

        $routePathSegment = trim($uri, '/');
        $fullUri = $this->currentGroupPrefix;
        if ($routePathSegment !== '') {
            if ($fullUri === '') {
                $fullUri = '/' . $routePathSegment;
            } else {
                $fullUri = rtrim($fullUri, '/') . '/' . $routePathSegment;
            }
        } elseif ($fullUri === '') {
            $fullUri = '/';
        }
        if ($fullUri !== '/') {
            $fullUri = '/' . ltrim($fullUri, '/');
        }


        $methods = array_map('strtoupper', (array) $httpMethods);
        $paramDefinitions = [];
        $regex = $this->compileUriToRegex($fullUri, $paramDefinitions);

        // error_log("[Router Add] Pattern: '{$fullUri}', Regex: '{$regex}', ParamDefs: " . json_encode($paramDefinitions) . ", Handler: " . (is_array($handler) ? implode('::', $handler) : (is_object($handler) && $handler instanceof Closure ? 'Closure' : 'Unknown')));

        $baseRouteStructure = [
            'uri_pattern' => $fullUri,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $this->currentGroupMiddleware,
            'defaults' => $this->currentGroupDefaults,
            'name' => null,
            'param_definitions' => $paramDefinitions // Store full definitions
        ];

        foreach ($methods as $method) {
            if (!in_array($method, self::HTTP_METHODS)) {
                throw new InvalidArgumentException("Unsupported HTTP method: {$method}");
            }
            $routeEntry = $baseRouteStructure;
            $routeEntry['method'] = $method;
            $this->routes[] = $routeEntry;
            $this->lastAddedRouteKeys[] = array_key_last($this->routes);
        }

        return $this;
    }

    public function middleware(array $middleware): self
    {
        if (empty($this->lastAddedRouteKeys)) {
            throw new \LogicException("Cannot apply middleware. No route defined prior.");
        }
        foreach ($this->lastAddedRouteKeys as $key) {
            if (isset($this->routes[$key])) {
                $this->routes[$key]['middleware'] = array_unique(array_merge($this->routes[$key]['middleware'], $middleware));
            }
        }
        return $this;
    }

    public function defaults(array $defaults): self
    {
        if (empty($this->lastAddedRouteKeys)) {
            throw new \LogicException("Cannot apply defaults. No route defined prior.");
        }
        foreach ($this->lastAddedRouteKeys as $key) {
            if (isset($this->routes[$key])) {
                $this->routes[$key]['defaults'] = array_merge($this->routes[$key]['defaults'], $defaults);
            }
        }
        return $this;
    }

    public function name(string $name): self
    {
        if (empty($this->lastAddedRouteKeys)) {
            throw new \LogicException("Cannot apply name. No route defined prior.");
        }
        foreach ($this->lastAddedRouteKeys as $key) {
            if (isset($this->routes[$key])) {
                $this->routes[$key]['name'] = $name;
                $this->namedRoutes[$name][$this->routes[$key]['method']] = $this->routes[$key]['uri_pattern'];
            }
        }
        return $this;
    }

    public function setExtra(array $data): self
    {
        if (empty($this->lastAddedRouteKeys)) {
            throw new \LogicException("Cannot apply extra data. No route defined prior.");
        }
        foreach ($this->lastAddedRouteKeys as $key) {
            if (isset($this->routes[$key])) {
                $this->routes[$key]['extra'] = array_merge($this->routes[$key]['extra'] ?? [], $data);
            }
        }
        return $this;
    }

    /**
     * Sets the cache time-to-live (in seconds) for the last defined route.
     *
     * @param int $ttl The cache duration in seconds.
     * @return $this
     */
    public function cache(int $ttl): self
    {
        if (empty($this->lastAddedRouteKeys)) {
            throw new \LogicException("Cannot apply cache. No route defined prior.");
        }
        // A TTL of 0 or less means do not cache.
        if ($ttl <= 0) {
            return $this;
        }
        $this->setExtra(['cache_ttl' => $ttl]);
        return $this;
    }

    public function get(string $uri, mixed $handler): self
    {
        return $this->addRouteDefinition('GET', $uri, $handler);
    }

    public function post(string $uri, mixed $handler): self
    {
        return $this->addRouteDefinition('POST', $uri, $handler);
    }

    public function put(string $uri, mixed $handler): self
    {
        return $this->addRouteDefinition('PUT', $uri, $handler);
    }

    public function patch(string $uri, mixed $handler): self
    {
        return $this->addRouteDefinition('PATCH', $uri, $handler);
    }

    public function delete(string $uri, mixed $handler): self
    {
        return $this->addRouteDefinition('DELETE', $uri, $handler);
    }

    public function options(string $uri, mixed $handler): self
    {
        return $this->addRouteDefinition('OPTIONS', $uri, $handler);
    }

    public function head(string $uri, mixed $handler): self
    {
        return $this->addRouteDefinition('HEAD', $uri, $handler);
    }

    public function any(string $uri, mixed $handler): self
    {
        return $this->addRouteDefinition(self::HTTP_METHODS, $uri, $handler);
    }

    public function addRedirect(string $fromPath, string $toTarget, int $statusCode = 301, ?string $routeNameTarget = null, ?array $targetAction = null): self
    {
        // $this->lastAddedRouteKey = null;
        $routePathSegment = trim($fromPath, '/');
        $fullFromPath = $this->currentGroupPrefix;
        if ($routePathSegment !== '') {
            if ($fullFromPath === '') {
                $fullFromPath = '/' . $routePathSegment;
            } else {
                $fullFromPath = rtrim($fullFromPath, '/') . '/' . $routePathSegment;
            }
        } elseif ($fullFromPath === '') {
            $fullFromPath = '/';
        }
        if ($fullFromPath !== '/')
            $fullFromPath = '/' . ltrim($fullFromPath, '/');
        $paramDefinitions = [];
        $regex = $this->compileUriToRegex($fullFromPath, $paramDefinitions);
        $this->redirectRoutes[] = [
            'regex' => $regex,
            'param_definitions' => $paramDefinitions,
            'target' => $toTarget,
            'status_code' => $statusCode,
            'is_route_name_target' => $routeNameTarget !== null,
            'target_action' => $targetAction,
        ];
        // $this->lastAddedRedirectRouteKey = array_key_last($this->redirectRoutes);
        return $this;
    }

    public function group(array|string $attributes, callable $callback): void
    {
        $previousGroupPrefix = $this->currentGroupPrefix;
        $previousGroupMiddleware = $this->currentGroupMiddleware;
        $previousGroupDefaults = $this->currentGroupDefaults;
        $prefixToAdd = '';
        $middlewareToAdd = [];
        $defaultsToAdd = [];
        if (is_string($attributes)) {
            $prefixToAdd = $attributes;
        } else {
            $prefixToAdd = $attributes['prefix'] ?? '';
            $middlewareToAdd = (array) ($attributes['middleware'] ?? []);
            $defaultsToAdd = (array) ($attributes['defaults'] ?? []);
        }
        $newPrefixSegment = trim($prefixToAdd, '/');
        if ($this->currentGroupPrefix === '') {
            $this->currentGroupPrefix = ($newPrefixSegment === '') ? '' : '/' . $newPrefixSegment;
        } else {
            if ($newPrefixSegment !== '') {
                $this->currentGroupPrefix = $this->currentGroupPrefix . '/' . $newPrefixSegment;
            }
        }
        if ($this->currentGroupPrefix === '/')
            $this->currentGroupPrefix = '';
        $this->currentGroupMiddleware = array_unique(array_merge($previousGroupMiddleware, $middlewareToAdd));
        $this->currentGroupDefaults = array_merge($previousGroupDefaults, $defaultsToAdd);
        $callback($this);
        $this->currentGroupPrefix = $previousGroupPrefix;
        $this->currentGroupMiddleware = $previousGroupMiddleware;
        $this->currentGroupDefaults = $previousGroupDefaults;
    }

    public function dispatch(string $httpMethod, string $uri): array
    {
        $normalizedUri = '/' . trim($uri, '/');
        if ($normalizedUri === '' && $uri !== '')
            $normalizedUri = $uri;
        elseif ($normalizedUri === '')
            $normalizedUri = '/';
        // error_log("--- Dispatching URI: '{$normalizedUri}', Method: '{$httpMethod}' ---");

        foreach ($this->redirectRoutes as $idx => $redirectRoute) {
            if (preg_match($redirectRoute['regex'], $normalizedUri, $matches)) {
                // error_log("MATCHED Redirect Route #{$idx}: Regex='{$redirectRoute['regex']}'");
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return [
                    'type' => 'redirect',
                    'target' => $redirectRoute['target'],
                    'status_code' => $redirectRoute['status_code'],
                    'is_route_name_target' => $redirectRoute['is_route_name_target'],
                    'target_action' => $redirectRoute['target_action'],
                    'parameters' => $params
                ];
            }
        }

        $allowedMethodsForUri = [];
        foreach ($this->routes as $idx => $route) {
            $matchResult = preg_match($route['regex'], $normalizedUri, $matches);

            if ($matchResult) {
                //error_log("MATCHED Handler Route #{$idx}: Pattern='{$route['uri_pattern']}', Regex='{$route['regex']}', Matches=" . json_encode($matches));
                $allowedMethodsForUri[] = $route['method'];

                if (strtoupper($httpMethod) === $route['method']) {
                    $rawParameters = [];
                    foreach ($route['param_definitions'] as $paramDef) {
                        $paramName = $paramDef['name'];
                        $rawParameters[$paramName] = $matches[$paramName] ?? null;
                    }

                    // --- Parameter Type Validation ---
                    $validatedParameters = [];
                    foreach ($route['param_definitions'] as $paramDef) {
                        $name = $paramDef['name'];
                        $type = $paramDef['type'];
                        $isOptional = $paramDef['optional'];
                        $rawValue = $rawParameters[$name] ?? null;

                        if ($rawValue === null) {
                            if (isset($route['defaults'][$name])) {
                                $validatedParameters[$name] = $route['defaults'][$name];
                                continue;
                            }
                            if ($isOptional) {
                                $validatedParameters[$name] = null; // Explicitly null for optional not provided
                                continue;
                            }
                            // If not optional and no default, this is an issue if it wasn't matched,
                            // but regex should ensure required params are present in $matches.
                            // This might indicate a regex generation issue for required params.
                        }

                        // Validate type if a type is specified
                        if ($type !== null && $type !== '*' && $rawValue !== null) {
                            $validatorRegex = self::PARAM_TYPES_REGEX_VALIDATORS[$type] ?? null;
                            if ($validatorRegex && !preg_match($validatorRegex, (string) $rawValue)) {
                                throw new RouteParameterTypeMismatchException($name, $type, $rawValue, code: 400);
                            }
                        }
                        $validatedParameters[$name] = $rawValue;
                    }
                    // --- End Parameter Type Validation ---

                    // Apply defaults again for any params that became null after validation (e.g., optional not present)
                    // and should receive a default.
                    foreach ($route['defaults'] as $paramName => $defaultValue) {
                        if (!isset($validatedParameters[$paramName])) {
                            $validatedParameters[$paramName] = $defaultValue;
                        }
                    }

                    $handler = $route['handler'];
                    $methodName = null;
                    if (is_array($handler) && count($handler) === 2) {
                        $controller = $handler[0];
                        $methodName = $handler[1];
                    } elseif (is_string($handler) && class_exists($handler) && method_exists($handler, '__invoke')) {
                        $controller = $handler;
                        $methodName = '__invoke';
                    } elseif ($handler instanceof Closure) {
                        $controller = $handler;
                    } else {
                        throw new InvalidArgumentException("Invalid route handler for URI: {$route['uri_pattern']}");
                    }

                    $this->currentRoute = $route;

                    // Also store in request attributes for middleware access
                    $this->currentRequest = $this->currentRequest->withAttribute('current_route', $route);

                    return [
                        'type' => 'handler',
                        'controller' => $controller,
                        'method' => $methodName,
                        'parameters' => $validatedParameters,
                        'middleware' => $route['middleware'] ?? [],
                        'name' => $route['name'] ?? null,
                        'defaults' => $route['defaults'] ?? [],
                        'uri_pattern' => $route['uri_pattern'],
                        'extra' => $route['extra'] ?? []
                    ];
                }
            }
        }
        if (!empty($allowedMethodsForUri)) {
            throw new HttpMethodNotAllowedException("Method $httpMethod not allowed for URI $normalizedUri.", array_unique($allowedMethodsForUri));
        }
        throw new RouteNotFoundException("No route found for URI $normalizedUri and method $httpMethod. Checked " . count($this->routes) . " routes.");
    }

    public function route(string $name, array $parameters = [], string $method = 'GET'): string
    {
        $method = strtoupper($method);
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException("Named route '$name' not found.");
        }
        if (isset($this->namedRoutes[$name][$method])) {
            $uriPattern = $this->namedRoutes[$name][$method];
        } else {
            if (isset($this->namedRoutes[$name]['GET'])) {
                $uriPattern = $this->namedRoutes[$name]['GET'];
            } elseif (!empty($this->namedRoutes[$name])) {
                $uriPattern = reset($this->namedRoutes[$name]);
            } else {
                throw new InvalidArgumentException("Named route '$name' has no URI patterns defined.");
            }
        }

        $url = $uriPattern;
        $usedParams = [];

        // Find the route definition to get param_definitions for accurate replacement $routeDefinitionForGeneration = null;
        foreach ($this->routes as $r) {
            if (($r['name'] ?? null) === $name && $r['uri_pattern'] === $uriPattern && $r['method'] === $method) {
                $routeDefinitionForGeneration = $r;
                break;
            }
        }
        // Fallback if exact method match not found for param defs (e.g., if GET was chosen)
        if (!$routeDefinitionForGeneration && isset($this->namedRoutes[$name]['GET']) && $uriPattern === $this->namedRoutes[$name]['GET']) {
            foreach ($this->routes as $r) {
                if (($r['name'] ?? null) === $name && $r['uri_pattern'] === $uriPattern && $r['method'] === 'GET') {
                    $routeDefinitionForGeneration = $r;
                    break;
                }
            }
        }


        $segments = explode('/', ltrim($url, '/'));
        $builtSegments = [];
        $paramIndex = 0;

        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT_PLACEHOLDER_CORE_PATTERN, $segment, $match) && $routeDefinitionForGeneration && isset($routeDefinitionForGeneration['param_definitions'][$paramIndex])) {
                $paramDef = $routeDefinitionForGeneration['param_definitions'][$paramIndex];
                $paramName = $paramDef['name'];
                $isOptional = $paramDef['optional'];

                if (array_key_exists($paramName, $parameters)) {
                    $builtSegments[] = (string) $parameters[$paramName];
                    $usedParams[$paramName] = true;
                } elseif ($isOptional) {
                    // Optional and not provided, skip this segment
                    continue;
                } elseif (isset($routeDefinitionForGeneration['defaults'][$paramName])) {
                    $builtSegments[] = (string) $routeDefinitionForGeneration['defaults'][$paramName];
                    $usedParams[$paramName] = true; // Consider default as used
                } else {
                    throw new InvalidArgumentException("Missing required parameter '{$paramName}' for named route '{$name}'.");
                }
                $paramIndex++;
            } else {
                $builtSegments[] = $segment;
            }
        }
        $url = '/' . implode('/', array_filter($builtSegments, fn($s) => $s !== null && $s !== '')); // Filter out empty segments from skipped optionals

        $queryParams = [];
        foreach ($parameters as $key => $value) {
            if (!isset($usedParams[$key])) {
                $queryParams[$key] = $value;
            }
        }
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $url = '/' . ltrim(str_replace('//', '/', $url), '/');
        return ($url === '' && $uriPattern === '/') ? '/' : (($url === '') ? '/' : $url);
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function getRedirectRoutes(): array
    {
        return $this->redirectRoutes;
    }

    /**
     * Get the current route that was matched during dispatch
     */
    public function getCurrentRoute(): ?array
    {
        return $this->currentRoute;
    }
}
