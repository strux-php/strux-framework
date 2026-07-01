<?php

declare(strict_types=1);

namespace Strux\Auth\Listeners;

use Psr\Log\LoggerInterface;
use Strux\Auth\Events\Authenticated;
use Strux\Auth\Events\LoginFailed;
use Strux\Auth\Events\LoggedOut;
use Strux\Auth\Events\PasswordReset;
use Strux\Auth\Events\Registered;
use Strux\Auth\Events\Validated;
use Strux\Auth\Events\Verified;

readonly class LogAuthenticationAction
{
    public function __construct(
        private LoggerInterface $logger
    )
    {
    }

    public function onLogin(Authenticated $event): void
    {
        $id = $this->getUserId($event->user);
        $email = $event->user->email ?? 'unknown';
        $this->logger->info("[Auth] User Logged In: ID {$id} ({$email})");
    }

    public function onLogout(LoggedOut $event): void
    {
        $id = $this->getUserId($event->user);
        $this->logger->info("[Auth] User Logged Out: ID {$id}");
    }

    public function onFailure(LoginFailed $event): void
    {
        $email = $event->credentials['email'] ?? 'unknown';
        $this->logger->warning("[Auth] Failed Login Attempt for email: {$email}");
    }

    public function onRegistered(Registered $event): void
    {
        $id = $this->getUserId($event->user);
        $email = $event->user->email ?? 'unknown';
        $this->logger->info("[Auth] User Registered: ID {$id} ({$email})");
    }

    public function onValidated(Validated $event): void
    {
        $id = $this->getUserId($event->user);
        $email = $event->user->email ?? 'unknown';
        $this->logger->info("[Auth] User Validated: ID {$id} ({$email})");
    }

    public function onVerified(Verified $event): void
    {
        $id = $this->getUserId($event->user);
        $email = $event->user->email ?? 'unknown';
        $this->logger->info("[Auth] User Email Verified: ID {$id} ({$email})");
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $id = $this->getUserId($event->user);
        $email = $event->user->email ?? 'unknown';
        $this->logger->info("[Auth] User Password Reset: ID {$id} ({$email})");
    }

    /**
     * Helper to safely extract the User ID from an unknown object.
     */
    private function getUserId(?object $user): string|int
    {
        if (!$user) {
            return 'unknown';
        }

        // 1. Framework Model Support (Best Practice)
        // If the object has a method to tell us its primary key name (e.g. 'id', 'user_id')
        if (method_exists($user, 'getPrimaryKey')) {
            $pkName = $user->getPrimaryKey();

            // Try to access it as a property first
            if (isset($user->{$pkName})) {
                return $user->{$pkName};
            }

            // Try getter method (e.g. getId() for 'id')
            $getter = 'get' . ucfirst($pkName);
            if (method_exists($user, $getter)) {
                return $user->{$getter}();
            }
        }

        // 2. Fallback: Common Standards
        if (isset($user->id)) return $user->id;
        if (isset($user->userId)) return $user->userId;
        if (isset($user->userID)) return $user->userID;
        if (isset($user->user_id)) return $user->user_id;
        if (method_exists($user, 'getId')) return $user->getId();

        return 'unknown';
    }
}