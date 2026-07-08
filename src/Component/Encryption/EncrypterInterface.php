<?php

declare(strict_types=1);

namespace Strux\Component\Encryption;

use Strux\Component\Exceptions\EncrypterException;

interface EncrypterInterface
{
    /**
     * Encrypt the given data.
     *
     * @param mixed $data Any serializable value.
     * @return string The encrypted payload (safe for transport/storage).
     * @throws EncrypterException
     */
    public function encrypt(mixed $data): string;

    /**
     * Decrypt the given payload back to its original value.
     *
     * @param string $payload The encrypted payload.
     * @return mixed The original data.
     * @throws EncrypterException
     */
    public function decrypt(string $payload): mixed;

    /**
     * Create an encrypter instance with a new key.
     * The original instance remains unchanged (immutable).
     *
     * @throws EncrypterException
     */
    public function withKey(string $key): EncrypterInterface;

    /**
     * Return the current encryption key as an ASCII string.
     */
    public function getKey(): string;
}
