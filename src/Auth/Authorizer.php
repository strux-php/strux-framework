<?php

declare(strict_types=1);

namespace Strux\Auth;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use Strux\Auth\Attributes\Policy;

class Authorizer
{
    private AuthManager $auth;
    private ContainerInterface $container;

    public function __construct(
        AuthManager        $auth,
        ContainerInterface $container
    )
    {
        $this->auth = $auth;
        $this->container = $container;
    }

    /**
     * Check if the authenticated user allows the given ability.
     *
     * @param string $ability The method name on the Policy (e.g., 'view', 'delete')
     * @param mixed $arguments The resource (Object/String) or array of arguments
     * @return bool
     */
    public function allows(string $ability, mixed $arguments = []): bool
    {
        $user = $this->auth->sentinel()->user();

        if (!$user) {
            return false;
        }

        $args = is_array($arguments) ? $arguments : [$arguments];

        $resource = $args[0] ?? null;

        if (!$resource) {
            return false;
        }

        $resourceClass = is_object($resource) ? get_class($resource) : $resource;

        // Resolve policy from #[Policy] attribute on the resource class
        $authorityClass = $this->resolvePolicyClass($resourceClass);

        if (!$authorityClass || !class_exists($authorityClass)) {
            return false;
        }

        $authorityInstance = $this->container->get($authorityClass);

        $method = 'can' . ucfirst($ability);

        if (!method_exists($authorityInstance, $method)) {
            return false;
        }

        try {
            return (bool)$authorityInstance->{$method}($user, ...$args);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve the policy class for a given resource by reading #[Policy] attribute.
     */
    private function resolvePolicyClass(string $resourceClass): ?string
    {
        try {
            $ref = new ReflectionClass($resourceClass);
            $attr = $ref->getAttributes(Policy::class)[0] ?? null;

            if ($attr) {
                return $attr->newInstance()->policy;
            }
        } catch (\Throwable) {
            // Reflection failed — class may not exist
        }

        return null;
    }
}