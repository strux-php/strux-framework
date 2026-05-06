<?php

declare(strict_types=1);

namespace Strux\Component\Routing\Attributes;

use Attribute;

/**
 * Class Route
 *
 * Defines a route attribute for controller methods.
 * Path syntax examples:
 * - /users
 * - /user/:id
 * - /profile/:username|? (optional username)
 * - /post/int:id
 * - /article/slug:title
 * - /files/*:path (wildcard, captures everything after /files/)
 * - /archive/int:year/(?:int:month/(?:int:day)?)? (optional segments)
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    public string $path;
    /** @var array<string> */
    public array $methods; // Changed from string to array, e.g., ['GET', 'POST']
    public ?string $name;
    public array $defaults;
    public ?string $toPath = null;    // Redirect to a specific path
    public ?string $toRoute = null;   // Redirect to a named route
    /** @var string|array|null */
    public $toAction = null; // Redirect to a controller action [Controller::class, 'method'] or 'Controller::method'
    /** @var array<int, class-string|object> */
    public array $middleware;

    /**
     * Route constructor.
     *
     * @param string $path The URI path for the route. Placeholders like :param, int:id, string:name, slug:title. Optional params: :param|?
     * @param array|string $methods HTTP method(s) (e.g., 'GET', ['GET', 'POST']). Defaults to ['GET'].
     * @param string|null $name Optional name for the route.
     * @param array $defaults Default values for route parameters (e.g., ['id' => 1, 'slug' => 'default-slug']).
     * @param string|null $toPath Redirect to this absolute path.
     * @param string|null $toRoute Redirect to this named route.
     * @param string|array|null $toAction Redirect to this controller action.
     * @param array<int, class-string|object> $middleware Optional middleware for this specific route.
     */
    public function __construct(
        string            $path,
        array|string      $methods = ['GET'], // Default to GET, allow an array
        ?string           $name = null,
        array             $defaults = [],
        ?string           $toPath = null,
        ?string           $toRoute = null,
        string|array|null $toAction = null,
        array             $middleware = []
    )
    {
        $this->path = $path;
        $this->methods = array_map('strtoupper', (array)$methods); // Ensure an array and uppercase
        $this->name = $name;
        $this->defaults = $defaults;
        $this->toPath = $toPath;
        $this->toRoute = $toRoute;
        $this->toAction = $toAction;
        $this->middleware = $middleware;
    }
}
