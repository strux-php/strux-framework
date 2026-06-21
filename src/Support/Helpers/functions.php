<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Strux\Auth\Auth;
use Strux\Component\Config\Config;
use Strux\Component\Debug\HtmlDumper;
use Strux\Component\Http\Request;
use Strux\Component\Http\Response;
use Strux\Component\Routing\Router;
use Strux\Component\Session\SessionInterface;
use Strux\Component\View\ViewInterface;
use Strux\Support\ContainerBridge;
use Strux\Support\Helpers\FlashInterface;
use Strux\Support\Helpers\SafeHtml;

if (!function_exists('container')) {
    function container(?string $id = null): mixed
    {
        if ($id === null) {
            return ContainerBridge::getContainer();
        }
        return ContainerBridge::get($id);
    }
}

if (!function_exists('request')) {
    function request(): Request
    {
        return ContainerBridge::get(Request::class);
    }
}

if (!function_exists('response')) {
    function response(string $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }
}

if (!function_exists('view')) {
    function view(string $filename, array $data = [], int $status = 200): Response
    {
        /** @var ViewInterface $viewEngine */
        $viewEngine = ContainerBridge::get(ViewInterface::class);

        try {
            $currentRequest = ContainerBridge::get(ServerRequestInterface::class);
            if ($currentRequest->getAttribute('csrf_token') && $currentRequest->getAttribute('csrf_field_name')) {
                $data['csrf_token'] = $currentRequest->getAttribute('csrf_token');
                $data['csrf_field_name'] = $currentRequest->getAttribute('csrf_field_name');
            }
        } catch (Throwable $e) {
            if (ContainerBridge::has(LoggerInterface::class)) {
                ContainerBridge::get(LoggerInterface::class)
                    ->debug('CSRF token not added to view data via helper: ServerRequestInterface not found in container.');
            }
        }

        if (ContainerBridge::has(Auth::class)) {
            $data['auth'] = ContainerBridge::get(Auth::class);
        }
        if (ContainerBridge::has(FlashInterface::class)) {
            $data['flash'] = ContainerBridge::get(FlashInterface::class);
        }


        $content = $viewEngine->render($filename, $data);

        $response = new Response($content, $status);
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $uri, int $status = 302): Response
    {
        return response()->redirect($uri, $status);
    }
}

if (!function_exists('redirectWith')) {
    function redirectWith(
        string $uri,
        array $messages = [],
        int $status = 302,
        bool $isRouteName = false,
        array $routeParams = []
    ): Response {
        /** @var FlashInterface $flash */
        $flash = ContainerBridge::get(FlashInterface::class);
        if ($flash) {
            foreach ($messages as $type => $message) {
                $flash->set((string) $type, $message);
            }
        } elseif (!empty($messages)) {
            if (ContainerBridge::has(LoggerInterface::class)) {
                ContainerBridge::get(LoggerInterface::class)
                    ->warning("FlashService not available, cannot flash messages for redirect.", [
                        'messages' => $messages
                    ]);
            }
        }

        $targetUri = $uri;
        if ($isRouteName) {
            /** @var Router $router */
            $router = ContainerBridge::get(Router::class);
            try {
                $targetUri = $router->route($uri, $routeParams);
            } catch (InvalidArgumentException $e) {
                ContainerBridge::get(LoggerInterface::class)
                    ->error("Failed to generate route for redirectWith: " . $e->getMessage(), [
                        'route_name' => $uri,
                        'exception' => $e
                    ]);
                $targetUri = '/';
            }
        }
        return redirect($targetUri, $status);
    }
}

if (!function_exists('to_route')) {
    function to_route(
        string $routeName,
        array $parameters = [],
        int $status = 302,
        array $flashMessages = []
    ): Response {
        return redirectWith(
            uri: $routeName,
            messages: $flashMessages,
            status: $status,
            isRouteName: true,
            routeParams: $parameters
        );
    }
}

if (!function_exists('route')) {
    /**
     * Generate a URL for a named route.
     */
    function route(string $routeName, array $queryParams = [], string $method = 'GET'): string
    {
        if (!ContainerBridge::has(Router::class)) {
            ContainerBridge::get(LoggerInterface::class)
                ->error("Router not found in container. Cannot generate route for '$routeName'.");
            throw new RuntimeException("Router service not available for URL generation.");
        }
        /** @var Router $router */
        $router = ContainerBridge::get(Router::class);
        return url($router->route($routeName, $queryParams, $method));
    }
}

if (!function_exists('json')) {
    function json(
        mixed $data,
        int $status = 200,
        array $headers = [],
        int $encodingOptions = 0
    ): Response {
        return response()->json($data, $status, $headers, $encodingOptions);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        } else {
            $value = getenv($key);
        }
        if ($value === false) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Access etc/config values.
     */
    function config(string $key = '', mixed $default = null): mixed
    {
        /** @var Config $configService */
        $configService = ContainerBridge::get(Config::class);
        if (!empty($key)) {
            return $configService->get($key, $default);
        }
        return $configService;
    }
}

if (!function_exists('class_uses_recursive')) {
    /**
     * Returns all traits used by a class, its parent classes and trait of traits.
     *
     * @param object|string $class
     * @return array
     */
    function class_uses_recursive(object|string $class): array
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = [];

        foreach (array_reverse(class_parents($class)) + [$class => $class] as $class) {
            $results += trait_uses_recursive($class);
        }

        return array_unique($results);
    }
}

if (!function_exists('trait_uses_recursive')) {
    /**
     * Returns all traits used by a trait.
     *
     * @param string $trait
     * @return array
     */
    function trait_uses_recursive(string $trait): array
    {
        $traits = class_uses($trait) ?: [];

        foreach ($traits as $usedTrait) {
            $traits += trait_uses_recursive($usedTrait);
        }

        return $traits;
    }
}

if (!function_exists('class_basename')) {
    /**
     * Get the class "basename" of the given object / class.
     *
     * @param string|object $class
     * @return string
     */
    function class_basename(string|object $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        static $cachedBaseUrl = null;

        if ($cachedBaseUrl === null) {
            try {
                if (class_exists(ContainerBridge::class) && ContainerBridge::has(Config::class)) {
                    $cachedBaseUrl = ContainerBridge::get(Config::class)->get('app.url', '');
                }
            } catch (Throwable $e) {
                // Ignore errors and fallback to other methods
            }

            if (empty($cachedBaseUrl)) {
                $cachedBaseUrl = rtrim(env('APP_URL') ?: '', '/');
            }

            if (empty($cachedBaseUrl)) {
                $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443 ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                $requestUri = $_SERVER['REQUEST_URI'] ?? '';

                if (str_starts_with($requestUri, $scriptName)) {
                    $base = $scriptName;
                } else {
                    $base = rtrim(dirname($scriptName), '/\\');
                }
                $cachedBaseUrl = $scheme . '://' . $host . $base;
            }
        }

        return rtrim($cachedBaseUrl, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $filepath): string
    {
        return url('assets/' . ltrim($filepath, '/'));
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the full filesystem path to the storage directory.
     * Often used for internal file operations (saving, reading).
     */
    function storage_path(string $path = ''): string
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
        return rtrim($root, '/\\') . '/storage/' . ltrim($path, '/\\');
    }
}

if (!function_exists('storage_url')) {
    /**
     * Get the public URL for a file stored in the public/web storage.
     * Uses the 'web' disk configuration by default.
     */
    function storage_url(string $path = ''): string
    {
        static $storageBaseUrl = null;

        if ($storageBaseUrl === null) {
            try {
                if (class_exists(ContainerBridge::class) && ContainerBridge::has(Config::class)) {
                    $config = ContainerBridge::get(Config::class);
                    $storageBaseUrl = $config->get('filesystems.disks.web.url');
                }
            } catch (Throwable $e) {
                // Fallback if container/config fails
            }

            if (empty($storageBaseUrl)) {
                $storageBaseUrl = url('storage');
            }
        }

        return rtrim($storageBaseUrl, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('es_')) {
    function es_(mixed $value = null): string|\DateTimeInterface
    {
        if ($value instanceof SafeHtml) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null || $value === '') {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('safe_')) {
    function safe_(mixed $value = null): SafeHtml
    {
        $html = $value === null ? '' : (string) $value;
        $allowedTags = '<p><a><br><strong><em><li><ul><ol><b><i><u><span><div><h1><h2><h3><h4><h5><h6><small><blockquote><code><pre><img><hr><table><thead><tbody><tr><th><td>';
        $cleanHtml = strip_tags($html, $allowedTags);
        $cleanHtml = preg_replace('/on\w+="[^"]*"/i', '', $cleanHtml);
        return new SafeHtml($cleanHtml);
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($value, string $format = 'l, F jS, Y (H:i)'): string
    {
        try {
            if (empty($value))
                return '';
            $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
            if ($timestamp === false)
                return (string) $value;
            return date($format, $timestamp);
        } catch (Throwable $e) {
            return (string) $value;
        }
    }
}

if (!function_exists('time_ago')) {
    function time_ago(string|DateTimeInterface $datetime, bool $full = false): string
    {
        $now = new DateTime();
        if ($datetime instanceof DateTimeInterface) {
            $ago = $datetime;
        } else {
            $ago = new DateTime($datetime);
        }

        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full)
            $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}

if (!function_exists('isActive')) {
    function isActive(string $path, string $className = 'active'): string
    {
        try {
            return request()->getPath() === '/' . ltrim($path, '/') ? $className : '';
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('cache')) {
    function cache(?string $key = null, mixed $default = null): mixed
    {
        /** @var CacheInterface $cache */
        $cache = ContainerBridge::get(CacheInterface::class);
        if ($key !== null) {
            return $cache->get($key, $default);
        }
        return $cache;
    }
}

if (!function_exists('logger')) {
    function logger(): LoggerInterface
    {
        return ContainerBridge::get(LoggerInterface::class);
    }
}

if (!function_exists('session')) {
    function session(): SessionInterface
    {
        return ContainerBridge::get(SessionInterface::class);
    }
}

if (!function_exists('auth')) {
    function auth(): Auth
    {
        return ContainerBridge::get(Auth::class);
    }
}

if (!function_exists('flash')) {
    function flash(): FlashInterface
    {
        return ContainerBridge::get(FlashInterface::class);
    }
}

if (!function_exists('method_override')) {
    function method_override(string $method): string
    {
        $allowedMethods = ['PUT', 'PATCH', 'DELETE'];
        $input = '';
        if (in_array(strtoupper($method), $allowedMethods, true)) {
            $input .= '<input type="hidden" name="_method" value="' . strtoupper($method) . '">';
        }
        return $input;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(bool $htmlTag = true): string
    {
        try {
            $token = \request()->getAttribute('csrf_token');
            $fieldName = \request()->getAttribute('csrf_field_name', '_csrf_token');

            if ($token && !$htmlTag) {
                return $token;
            }

            if ($token && $fieldName) {
                return '<input type="hidden" name="' . es_($fieldName) . '" value="' . es_($token) . '">';
            }
        } catch (Throwable $e) {
            if (ContainerBridge::has(LoggerInterface::class)) {
                ContainerBridge::get(LoggerInterface::class)
                    ->warning("CSRF token field helper failed: " . $e->getMessage());
            }
        }
        return '';
    }
}

if (!function_exists('dump')) {
    /**
     * Dumps information about one or more variables in a styled HTML format.
     *
     * @param mixed ...$vars Variables to dump.
     * @return void
     */
    function dump(...$vars): void
    {
        HtmlDumper::dump(...$vars);
    }
}

if (!function_exists('dd')) {
    /**
     * Dumps information about one or more variables and end execution of the script.
     *
     * @param mixed ...$vars Variables to dump.
     * @return void
     */
    function dd(...$vars): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        HtmlDumper::dd(...$vars);
    }
}

if (!function_exists('user')) {
    /**
     * Get the currently authenticated user.
     * 
     * @return \Strux\Auth\Entity\User|\App\Domain\Identity\Entity\User|null
     */
    function user()
    {
        return auth()->user();
    }
}
