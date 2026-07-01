<?php

declare(strict_types=1);

namespace Strux\Auth;

class AuthProxy
{
    private ?SentinelInterface $sentinel = null;

    public function __construct(
        private readonly AuthManager $authManager
    ) {}

    private function sentinel(): SentinelInterface
    {
        if ($this->sentinel === null) {
            $this->sentinel = $this->authManager->sentinel();
        }
        return $this->sentinel;
    }

    public function user(): ?object
    {
        return $this->sentinel()->user();
    }

    public function isAuthenticated(): bool
    {
        return $this->sentinel()->isAuthenticated();
    }

    public function id(): int|string|null
    {
        return $this->sentinel()->id();
    }

    public function login(object $user, bool $remember = false): void
    {
        $this->sentinel()->login($user, $remember);
    }

    public function logout(): void
    {
        $this->sentinel()->logout();
    }

    public function authenticate(array|object $credentials = [], bool $remember = false): bool
    {
        return $this->sentinel()->authenticate($credentials, $remember);
    }

    public function validate(array $credentials = []): bool
    {
        return $this->sentinel()->validate($credentials);
    }

    public function setUser(object $user): void
    {
        $this->sentinel()->setUser($user);
    }

    public function can(string $ability, mixed $arguments = []): bool
    {
        return $this->authManager->can($ability, $arguments);
    }

    public function cannot(string $ability, mixed $arguments = []): bool
    {
        return $this->authManager->cannot($ability, $arguments);
    }

    public function redirectFor(mixed $user): string
    {
        return $this->authManager->redirectFor($user);
    }
}
