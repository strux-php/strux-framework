<?php

declare(strict_types=1);

namespace Strux\Auth\Traits;

use App\Domain\Identity\Entity\Permissions;
use App\Domain\Identity\Entity\Roles;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Strux\Auth\JwtService;
use Strux\Component\Exceptions\Container\ContainerException;
use Strux\Support\ContainerBridge;

use function is_string, is_array, in_array;

trait WillAuthenticate
{
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password ?? '');
    }

    public function setPassword(mixed $password): void
    {
        if (is_string($password)) {
            $this->password = password_hash($password, PASSWORD_DEFAULT);
        } elseif (is_array($password) && isset($password['password'])) {
            $this->password = password_hash($password['password'], PASSWORD_DEFAULT);
        } else {
            throw new InvalidArgumentException('Password must be a string or an array with a "password" key.');
        }
    }

    /**
     * Create a new JWT for the user.
     */
    public function createToken(): string
    {
        try {
            /** @var JwtService $jwtService */
            $jwtService = ContainerBridge::resolve(JwtService::class);
            return $jwtService->generateToken($this);
        } catch (ContainerException | NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            throw new InvalidArgumentException("Failed to resolve JWT Service: " . $e->getMessage(), 0, $e);
        }
    }

    public function getAuthIdentifier()
    {
        $pk = $this->getPrimaryKey();
        return $this->{$pk};
    }

    /**
     * Check if user has a specific role (string or array).
     */
    public function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        if ($this->roles->isEmpty()) {
            $this->roles = $this->__get('roles');
        }

        /* if (in_array($this->role, $roles)) {
            return true;
        } */

        /** @var Roles $role */
        foreach ($this->roles as $role) {
            if (in_array($role->slug, $roles) || in_array($role->name, $roles)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has a specific permission (via roles).
     */
    public function hasPermission(string|array $permissions): bool
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        if ($this->roles->isEmpty()) {
            $this->__get('roles');
        }

        /** @var Roles $role */
        foreach ($this->roles as $role) {
            if (!isset($role->permissions) || $role->permissions->isEmpty()) {
                $role->permissions;
            }

            /** @var Permissions $permission */
            foreach ($role->permissions as $permission) {
                if (in_array($permission->slug, $permissions) || in_array($permission->name, $permissions)) {
                    return true;
                }
            }
        }

        return false;
    }
}
