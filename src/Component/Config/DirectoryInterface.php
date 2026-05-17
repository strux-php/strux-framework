<?php

declare(strict_types=1);

namespace Strux\Component\Config;

/**
 * Contract for resolving application directory paths.
 *
 * Provides a clean, injectable way to access directory paths
 * throughout the framework without hardcoding paths or relying
 * on global constants.
 */
interface DirectoryInterface
{
    /**
     * Get the absolute path for the given directory key.
     *
     * @param string $key The directory key (e.g., 'controllers', 'views', 'cache')
     * @return string The absolute path
     * @throws \InvalidArgumentException If the key is not registered
     */
    public function get(string $key): string;

    /**
     * Check if a directory key is registered.
     */
    public function has(string $key): bool;

    /**
     * Get all registered directory paths.
     *
     * @return array<string, string>
     */
    public function all(): array;

    /**
     * Get the application root path.
     */
    public function rootPath(): string;

    /**
     * Set a directory path.
     *
     * @param string $key The directory key (e.g., 'controllers', 'views', 'cache')
     * @param string $value The absolute path
     */
    public function set(string $key, string $value): void;

    /**
     * Remove a directory path.
     *
     * @param string $key The directory key (e.g., 'controllers', 'views', 'cache')
     */
    public function remove(string $key): void;
}
