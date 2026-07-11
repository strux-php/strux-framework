<?php

declare(strict_types=1);

namespace Strux\Component\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Strux\Component\Encryption\EncrypterInterface;
use Strux\Component\Cookie\CookieInterface;

class EncryptCookiesMiddleware implements MiddlewareInterface
{
    private EncrypterInterface $encrypter;
    private CookieInterface $cookie;
    private ?LoggerInterface $logger;
    private array $except;
    private string $encryptedPrefix;

    public function __construct(
        EncrypterInterface $encrypter,
        CookieInterface    $cookie,
        ?LoggerInterface  $logger = null,
        array              $config = []
    ) {
        $this->encrypter = $encrypter;
        $this->cookie = $cookie;
        $this->logger = $logger;
        $this->except = array_flip($config['except'] ?? []);
        $this->encryptedPrefix = $config['prefix'] ?? 'enc_';
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->decryptRequestCookies($request);

        $response = $handler->handle($request);

        return $this->encryptResponseCookies($response);
    }

    private function decryptRequestCookies(ServerRequestInterface $request): ServerRequestInterface
    {
        $cookies = $request->getCookieParams();
        $decrypted = [];

        foreach ($cookies as $name => $value) {
            if (isset($this->except[$name])) {
                $decrypted[$name] = $value;
                continue;
            }

            if (!is_string($value) || !str_starts_with($value, $this->encryptedPrefix)) {
                $decrypted[$name] = $value;
                continue;
            }

            try {
                $raw = substr($value, strlen($this->encryptedPrefix));
                $decrypted[$name] = $this->encrypter->decrypt($raw);
            } catch (\Throwable $e) {
                $this->logger?->warning('[EncryptCookiesMiddleware] Failed to decrypt cookie.', [
                    'cookie' => $name,
                    'error' => $e->getMessage(),
                ]);
                $decrypted[$name] = null;
            }
        }

        return $request->withCookieParams($decrypted);
    }

    private function encryptResponseCookies(ResponseInterface $response): ResponseInterface
    {
        if (!$response->hasHeader('Set-Cookie')) {
            return $response;
        }

        $headerValues = $response->getHeader('Set-Cookie');
        $response = $response->withoutHeader('Set-Cookie');

        foreach ($headerValues as $headerValue) {
            $parts = array_map('trim', explode(';', $headerValue));
            $nameValue = array_shift($parts);
            $equalPos = strpos($nameValue, '=');

            if ($equalPos === false) {
                $response = $response->withAddedHeader('Set-Cookie', $headerValue);
                continue;
            }

            $name = substr($nameValue, 0, $equalPos);
            $value = substr($nameValue, $equalPos + 1);

            if (isset($this->except[$name])) {
                $response = $response->withAddedHeader('Set-Cookie', $headerValue);
                continue;
            }

            try {
                $encryptedValue = $this->encryptedPrefix . $this->encrypter->encrypt($value);
                $newCookieHeader = $name . '=' . $encryptedValue;
                foreach ($parts as $part) {
                    $newCookieHeader .= '; ' . $part;
                }
                $response = $response->withAddedHeader('Set-Cookie', $newCookieHeader);
            } catch (\Throwable $e) {
                $this->logger?->warning('[EncryptCookiesMiddleware] Failed to encrypt cookie.', [
                    'cookie' => $name,
                    'error' => $e->getMessage(),
                ]);
                $response = $response->withAddedHeader('Set-Cookie', $headerValue);
            }
        }

        return $response;
    }
}
