<?php

namespace Strux\Component\Config;

use ArrayAccess;

class Config implements ArrayAccess
{
    /**
     * All of our configuration items
     *
     * @var array<string,mixed>
     */
    private array $items;
    private ?string $configDir;

    /**
     * @param array<string,mixed> $items Initial etc (e.g., from $_SERVER, $_ENV or a parsed etc file)
     */
    public function __construct(array $items = [], ?string $configDir = null)
    {
        $this->configDir = $configDir;
        $this->items = $items;

        // Auto-load class-based configurations
        $this->loadConfigurationClasses();

        // Sort the top-level keys (filenames/groups) alphabetically
        ksort($this->items);
        $this->items = array_merge($this->items, $_SERVER, $_ENV);
    }

    /**
     * Scan src/Config for classes and load them.
     */
    protected function loadConfigurationClasses(): void
    {
        $configDir = $this->configDir ?? \Strux\Component\Config\DirectoryResolver::getDefaults(getcwd())['config'];

        if (!is_dir($configDir)) {
            return;
        }

        $files = glob($configDir . '/*.php');

        foreach ($files as $file) {
            $filename = basename($file, '.php');
            $key = strtolower($filename);

            // 1. Try to include the file and capture the return value
            // This supports: return new class implements ConfigInterface { ... };
            $returned = include_once $file;

            if (is_object($returned)) {
                $this->mergeConfigObject($key, $returned);
                continue;
            }

            // 2. Fallback to Class Name inference (Standard named classes)
            $className = "App\\Config\\{$filename}";

            if (class_exists($className)) {
                $this->mergeConfigObject($key, new $className());
            }
        }
    }

    /**
     * Helper to merge config object data.
     */
    protected function mergeConfigObject(string $key, object $configInstance): void
    {
        /** @var ConfigInterface $configInstance */
        if (method_exists($configInstance, 'toArray')) {
            $configData = $configInstance->toArray();

            // Key the config by the filename (lowercased)
            // e.g. Application.php -> 'app' => [...]

            // Merge into existing items (Class config overrides array config if conflict)
            $this->items[$key] = array_merge($this->items[$key] ?? [], $configData);
        }
    }

    /**
     * Get the specified configuration value.
     * Returns $default if the key does not exist.
     * Supports “dot” notation.
     *
     * @param string $key
     * @param mixed $default
     * @param mixed|null $type
     * @return mixed
     */
    public function get(string $key, mixed $default = null, mixed $type = null): mixed
    {
        $segments = explode('.', $key);
        $config = $this->items;

        foreach ($segments as $segment) {
            if (is_array($config) && array_key_exists($segment, $config)) {
                $config = $config[$segment];
            } else {
                return $default;
            }
        }

        // If a type is specified, convert the value to that type
        if ($type !== null) {
            return match ($type) {
                'int' => (int) $config,
                'float' => (float) $config,
                'bool' => (bool) $config,
                'array' => (array) $config,
                'string' => (string) $config,
                default => $config
            };
        }
        return $config ?? $default;
    }

    /**
     * Set a configuration value using dot notation.
     * Overwrites existing values and converts intermediate non-array values to arrays.
     *
     * @param string $key
     * @param mixed $value
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &$this->items;

        while (count($segments) > 1) {
            $segment = array_shift($segments);

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $lastSegment = array_shift($segments);
        $current[$lastSegment] = $value;
    }

    /**
     * Determine if the given configuration value exists.
     * Supports “dot” notation for nested arrays.
     */
    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $config = $this->items;

        foreach ($segments as $segment) {
            if (is_array($config) && array_key_exists($segment, $config)) {
                $config = $config[$segment];
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove a configuration value using dot notation.
     * Does nothing if the key does not exist.
     *
     * @param string $key
     */
    public function remove(string $key): void
    {
        $segments = explode('.', $key);
        $current = &$this->items;
        $lastSegment = array_pop($segments);

        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                return;
            }
            $current = &$current[$segment];
        }

        if (isset($current[$lastSegment])) {
            unset($current[$lastSegment]);
        }
    }

    /**
     * Get all configuration items as an associative array.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Allow array-style access: $etc['foo.bar']
     */
    public function offsetExists($offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet($offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet($offset, $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset($offset): void
    {
        $this->remove((string) $offset);
    }
}

