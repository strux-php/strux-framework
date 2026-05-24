<?php

declare(strict_types=1);

namespace Strux\Auth;

use RuntimeException;
use Strux\Component\Config\Config;
use Strux\Component\ORM\Model;

class DatabaseUserProvider implements UserProviderInterface
{
    protected string $model;

    public function __construct(Config $config)
    {
        $this->model = $config->get('auth.providers.users.model')
            ?? $config->get('auth.sentinels.web.model')
            ?? 'App\\Domain\\Identity\\Entity\\User';

        if (!class_exists($this->model)) {
            throw new RuntimeException("Auth user model '{$this->model}' not found in configuration.");
        }
    }

    public function retrieveById(mixed $identifier): ?object
    {
        /* @var Model $modelInstance */
        $modelInstance = $this->model;
        return $modelInstance::find($identifier, includes: [
            'roles', 'roles.permissions'
        ]);
    }

    public function retrieveByCredentials(array $credentials): ?object
    {
        /* @var Model $modelInstance */
        $modelInstance = $this->model;

        $query = $modelInstance::query();

        foreach ($credentials as $key => $value) {
            if (!str_contains($key, 'password')) {
                $query->where($key, $value)
                    ->include('roles')
                    ->include('roles.permissions');
            }
        }

        return $query->first();
    }

    public function validateCredentials(object $user, array $credentials): bool
    {
        $plain = $credentials['password'] ?? '';

        // Check if the model has a specific verification method
        if (method_exists($user, 'verifyPassword')) {
            return $user->verifyPassword($plain);
        }

        // Default to standard password_verify on a 'password' property
        return password_verify($plain, $user->password ?? '');
    }
}
