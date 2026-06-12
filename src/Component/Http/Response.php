<?php

declare(strict_types=1);

namespace Strux\Component\Http;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface as Psr7ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface as Psr17StreamFactoryInterface;

class Response
{
    public const HTTP_OK = 200;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_NOT_ACCEPTABLE = 406;
    public const HTTP_CONFLICT = 409;
    public const HTTP_GONE = 410;
    public const HTTP_UNSUPPORTED_MEDIA_TYPE = 415;
    public const HTTP_UNPROCESSABLE_ENTITY = 422;
    public const HTTP_TOO_MANY_REQUESTS = 429;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;
    public const HTTP_SERVICE_UNAVAILABLE = 503;
    protected string $content = '';
    protected int $statusCode = 200;
    protected array $headers = [];

    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->setContent($content);
        $this->setStatusCode($status);
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function setStatusCode(int $code): self
    {
        if ($code < 100 || $code >= 599) {
            throw new InvalidArgumentException("The HTTP status code \"$code\" is not valid.");
        }
        $this->statusCode = $code;
        return $this;
    }

    public function setHeader(string $name, string|array $value, bool $replace = true): self
    {
        $normalizedName = $this->normalizeHeaderName($name);
        if ($replace || !isset($this->headers[$normalizedName])) {
            $this->headers[$normalizedName] = [];
        }
        foreach ((array)$value as $v) {
            $this->headers[$normalizedName][] = (string)$v;
        }
        return $this;
    }

    public function addHeader(string $name, string|array $value): self
    {
        return $this->setHeader($name, $value, false);
    }

    public function json(mixed $data, int $status = 200, array $headers = [], int $options = 0): self
    {
        $this->setStatusCode($status);
        $this->setHeader('Content-Type', 'application/json', true);
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
        try {
            $this->setContent(json_encode($data, JSON_THROW_ON_ERROR | $options));
        } catch (JsonException $e) {
            $this->setStatusCode(500);
            $this->setContent(json_encode(['error' => 'Failed to encode JSON response: ' . $e->getMessage()]));
        }
        return $this;
    }

    public function redirect(string $url, int $status = 302): self
    {
        $this->setStatusCode($status);
        $this->setHeader('Location', $url);
        $this->setContent('');
        return $this;
    }

    /**
     * Sets headers to prevent the response from being cached.
     * FIX: This now correctly modifies the internal $headers array.
     */
    public function noCache(): self
    {
        $this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->addHeader('Cache-Control', 'post-check=0, pre-check=0'); // For legacy IE
        $this->setHeader('Pragma', 'no-cache');
        $this->setHeader('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
        return $this;
    }

    /**
     * Sets cache-related headers.
     * FIX: This now correctly modifies the internal $headers array.
     */
    public function setCache(array $options = []): self
    {
        if (empty($options)) return $this;

        $cacheControl = [];
        if (isset($options['etag'])) {
            $this->setHeader('ETag', $options['etag']);
        }
        if (isset($options['last_modified'])) {
            $this->setLastModified($options['last_modified']);
        }
        if (isset($options['max_age'])) {
            $cacheControl[] = 'max-age=' . $options['max_age'];
        }
        if (isset($options['s_maxage'])) {
            $cacheControl[] = 's-maxage=' . $options['s_maxage'];
        }
        if ($options['web'] ?? false) {
            $cacheControl[] = 'web';
        }
        if ($options['private'] ?? false) {
            $cacheControl[] = 'private';
        }

        if (!empty($cacheControl)) {
            $this->setHeader('Cache-Control', implode(', ', $cacheControl));
        }

        return $this;
    }

    public function setLastModified($date): self
    {
        if ($date instanceof \DateTimeInterface) {
            $utcDate = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));
            $this->setHeader('Last-Modified', $utcDate->format('D, d M Y H:i:s') . ' GMT');
        } elseif (is_string($date)) {
            $this->setHeader('Last-Modified', $date);
        }
        return $this;
    }

    private function normalizeHeaderName(string $name): string
    {
        $name = str_replace('_', '-', strtolower($name));
        return implode('-', array_map('ucfirst', explode('-', $name)));
    }

    /**
     * Converts this custom Response to a PSR-7 ResponseInterface.
     * The ResponseFactory is no longer needed as we instantiate our native response directly.
     *
     * @param Psr17StreamFactoryInterface $psr17StreamFactory PSR-17 Stream Factory.
     * @return Psr7ResponseInterface
     */
    public function toPsr7Response(
        Psr17StreamFactoryInterface $psr17StreamFactory
    ): Psr7ResponseInterface
    {
        // Create our native PSR-7 response directly
        $psr7Response = new \Strux\Component\Http\Psr7\Response($this->statusCode, $this->headers);
        // Create the body stream and attach it
        $bodyStream = $psr17StreamFactory->createStream($this->content);
        return $psr7Response->withBody($bodyStream);
    }
}
