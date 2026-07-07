<?php

declare(strict_types=1);

namespace Strux\Auth\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Strux\Component\Database\ORM\Model;
use Strux\Component\Database\Schema\Attributes\Column;
use Strux\Component\Database\Schema\Attributes\Id;
use Strux\Component\Database\Schema\Attributes\Unique;
use Strux\Component\Database\Schema\Types\Field;
use Strux\Component\Database\ORM\Attributes\Reformat;
use Strux\Component\Database\ORM\Attributes\Hidden;
use Strux\Component\Database\Schema\Attributes\Entity;
use Strux\Component\Database\ORM\Attributes\OwnedByMany;
use Strux\Component\Events\EventDispatcher;
use Strux\Support\ContainerBridge;
use Strux\Support\Collection;

#[Entity(table: 'users')]
class User extends Model
{
	#[Id(autoincrement: false, autoGenerate: 'uuid')]
	#[Column(type: Field::uuid)]
	public string $id = '';

	#[Column]
	#[Reformat(get: 'ucwords')]
	public ?string $name = null;

	#[Unique]
	#[Column]
	#[Reformat(get: 'strtolower')]
	public ?string $email = null;

	#[Column]
	#[Hidden]
	public ?string $password = null;

	#[Column(type: Field::dateTime, nullable: true)]
	#[Hidden]
	public ?DateTimeInterface $email_verified_at = null;

	#[Column(type: Field::dateTime, nullable: true)]
	#[Hidden]
	public ?DateTimeInterface $last_login_at = null;

	#[Column(nullable: true)]
	public ?string $remember_token = null;

	#[Column(nullable: true)]
	public ?string $email_verification_token = null;

	#[Column(type: Field::dateTime, nullable: true)]
	public ?DateTimeInterface $email_verification_expires_at = null;

	#[Column(nullable: true)]
	public ?string $password_reset_token = null;

	#[Column(type: Field::dateTime, nullable: true)]
	public ?DateTimeInterface $reset_token_expires_at = null;

	#[OwnedByMany(
		related: Role::class,
		pivotTable: 'roles_users',
		foreignPivotKey: 'users_id',
		relatedPivotKey: 'roles_id'
	)]
	/** @var Collection<Role> */
	public Collection $roles;

	/**
	 * Generate an email verification token.
	 *
	 * @param int|DateTimeInterface|null $expiresAt Unix timestamp, DateTimeInterface, or null (defaults to +1 hour)
	 * @return string The raw (un-hashed) token
	 */
	public function generateVerificationToken(int|DateTimeInterface|null $expiresAt = null): string
	{
		if ($expiresAt === null) {
			$expiresAt = time() + 3600;
		}

		if (is_int($expiresAt)) {
			$expiresAt = new DateTimeImmutable("@{$expiresAt}");
		}

		$rawToken = bin2hex(random_bytes(32));
		$this->email_verification_token = hash('sha256', $rawToken);
		$this->email_verification_expires_at = $expiresAt;
		$this->save();

		return $rawToken;
	}

	/**
	 * Verify a raw token against this user's stored verification token.
	 *
	 * @param string $token The raw (un-hashed) token
	 * @return static|null $this if valid, null otherwise
	 */
	public function verifyToken(string $token): ?static
	{
		$hash = hash('sha256', $token);

		if (!hash_equals((string) $this->email_verification_token, $hash)) {
			return null;
		}

		$now = new DateTimeImmutable();
		if ($this->email_verification_expires_at !== null && $this->email_verification_expires_at < $now) {
			return null;
		}

		return $this;
	}

	/**
	 * Check if the user's email has been verified.
	 */
	public function isVerified(): bool
	{
		return $this->email_verified_at !== null;
	}

	/**
	 * Mark the user's email as verified, clear the token, and dispatch the Verified event.
	 */
	public function verifyEmail(): void
	{
		$this->email_verified_at = new DateTimeImmutable();
		$this->email_verification_token = null;
		$this->email_verification_expires_at = null;
		$this->save();

		try {
			/** @var EventDispatcher $dispatcher */
			$dispatcher = ContainerBridge::resolve(EventDispatcher::class);
			$dispatcher->dispatch(new \Strux\Auth\Events\Verified($this));
		} catch (\Throwable) {
			// Event dispatching is non-critical — don't block verification
		}
	}

	/**
	 * Generate a password reset token.
	 *
	 * @param int|DateTimeInterface|null $expiresAt Unix timestamp, DateTimeInterface, or null (defaults to +60 minutes)
	 * @return string The raw (un-hashed) token
	 */
	public function generatePasswordResetToken(int|DateTimeInterface|null $expiresAt = null): string
	{
		if ($expiresAt === null) {
			$expiresAt = time() + 3600;
		}

		if (is_int($expiresAt)) {
			$expiresAt = new DateTimeImmutable("@{$expiresAt}");
		}

		$rawToken = bin2hex(random_bytes(32));
		$this->password_reset_token = hash('sha256', $rawToken);
		$this->reset_token_expires_at = $expiresAt;
		$this->save();

		return $rawToken;
	}

	/**
	 * Verify a raw password reset token against this user's stored token.
	 *
	 * @param string $token The raw (un-hashed) token
	 * @return static|null $this if valid, null otherwise
	 */
	public function verifyPasswordResetToken(string $token): ?static
	{
		$hash = hash('sha256', $token);

		if (!hash_equals((string) $this->password_reset_token, $hash)) {
			return null;
		}

		$now = new DateTimeImmutable();
		if ($this->reset_token_expires_at !== null && $this->reset_token_expires_at < $now) {
			return null;
		}

		return $this;
	}

	/**
	 * Reset the user's password, clear the reset token, and dispatch the PasswordReset event.
	 */
	public function resetPassword(string $password): void
	{
		$this->setPassword($password);
		$this->password_reset_token = null;
		$this->reset_token_expires_at = null;
		$this->save();

		try {
			/** @var EventDispatcher $dispatcher */
			$dispatcher = ContainerBridge::resolve(EventDispatcher::class);
			$dispatcher->dispatch(new \Strux\Auth\Events\PasswordReset($this));
		} catch (\Throwable) {
			// Event dispatching is non-critical — don't block password reset
		}
	}
}
