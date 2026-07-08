<?php

declare(strict_types=1);

namespace Strux\Component\Encryption;

use Strux\Component\Exceptions\EncrypterException;

class Encrypter implements EncrypterInterface
{
    private const HMAC_ALGO = 'sha256';
    private const CIPHER = 'aes-256-cbc';
    private const IV_LENGTH = 16;

    private string $key;
    private string $cipher;

    /**
     * @param string $key The encryption key (must be 32 bytes for aes-256-cbc).
     * @param string $cipher The OpenSSL cipher method.
     * @throws EncrypterException
     */
    public function __construct(string $key, string $cipher = self::CIPHER)
    {
        if (!in_array($cipher, openssl_get_cipher_methods(), true)) {
            throw new EncrypterException("Unsupported cipher: {$cipher}");
        }

        $this->key = $key;
        $this->cipher = $cipher;
    }

    public function encrypt(mixed $data): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $serialized = serialize($data);

        $encrypted = openssl_encrypt($serialized, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new EncrypterException('Encryption failed: ' . openssl_error_string());
        }

        $payload = base64_encode($iv . $encrypted);
        $hmac = $this->hmac($payload);

        return base64_encode($hmac . '::' . $payload);
    }

    public function decrypt(string $payload): mixed
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new EncrypterException('Invalid payload encoding.');
        }

        $parts = explode('::', $decoded, 2);

        if (count($parts) !== 2) {
            throw new EncrypterException('Invalid payload format.');
        }

        [$hmac, $data] = $parts;

        if (!hash_equals($this->hmac($data), $hmac)) {
            throw new EncrypterException('Payload integrity check failed.');
        }

        $decodedData = base64_decode($data, true);

        if ($decodedData === false) {
            throw new EncrypterException('Invalid data encoding.');
        }

        $iv = substr($decodedData, 0, self::IV_LENGTH);
        $encrypted = substr($decodedData, self::IV_LENGTH);

        if ($encrypted === false || $encrypted === '') {
            throw new EncrypterException('No encrypted content found.');
        }

        $decrypted = openssl_decrypt($encrypted, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new EncrypterException('Decryption failed: ' . openssl_error_string());
        }

        $result = @unserialize($decrypted);

        if ($result === false && $decrypted !== 'b:0;') {
            throw new EncrypterException('Failed to unserialize decrypted data.');
        }

        return $result;
    }

    public function withKey(string $key): EncrypterInterface
    {
        return new self($key, $this->cipher);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    private function hmac(string $data): string
    {
        return hash_hmac(self::HMAC_ALGO, $data, $this->key, true);
    }
}
