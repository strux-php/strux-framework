<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Strux\Component\Encryption\Encrypter;
use Strux\Component\Encryption\EncrypterInterface;

class EncryptionRegistry extends ServiceRegistry
{
    public function build(): void
    {
        $this->container->singleton(EncrypterInterface::class, function () {
            $config = $this->config->get('encryption', []);
            $key = $config['key'] ?? '';

            if (empty($key)) {
                throw new \RuntimeException(
                    'Encryption key is not configured. Set ENCRYPTION_KEY in your .env file.'
                );
            }

            $cipher = strtolower($config['cipher'] ?? 'aes-256-cbc');

            return new Encrypter($key, $cipher);
        });
    }
}
