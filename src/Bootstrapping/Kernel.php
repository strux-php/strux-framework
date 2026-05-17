<?php

declare(strict_types=1);

namespace Strux\Bootstrapping;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;
use Dotenv\Exception\ValidationException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Strux\Bootstrapping\Registry\AppRegistry;
use Strux\Component\Config\Config;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\Config\DirectoryResolver;
use Strux\Foundation\Application;
use Strux\Foundation\Container;
use Strux\Support\ContainerBridge;

/**
 * Class Kernel
 *
 * The main entry point for creating and bootstrapping a Kernel application.
 */
class Kernel
{
    /**
     * Create and bootstrap the application.
     *
     * @param string $rootPath The root path of the application.
     * @param string|null $appClass The Application class to instantiate (defaults to Strux\Foundation\Application).
     * @param array<string, string> $directories User-defined directory overrides.
     * @return Application The bootstrapped application instance.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface|ReflectionException
     */
    public static function create(string $rootPath, ?string $appClass = null, array $directories = []): Application
    {
        /**
         * -------------------------------------------------------------------------
         * Load Environment Variables
         * -------------------------------------------------------------------------
         */
        if (class_exists(Dotenv::class) && file_exists($rootPath . '/.env')) {
            try {
                $dotenv = Dotenv::createImmutable($rootPath);
                $dotenv->load();
            } catch (InvalidPathException | ValidationException $e) {
                error_log("FATAL: Dotenv Exception: " . $e->getMessage());
                http_response_code(500);
                die("<h1>Application Configuration Error</h1><p>Essential configuration failed to load.</p>");
            }
        }

        /**
         * -------------------------------------------------------------------------
         * Create The Application Container
         * -------------------------------------------------------------------------
         */
        $container = new Container();
        $container->singleton(ContainerInterface::class, $container);
        ContainerBridge::setContainer($container);

        /**
         * -------------------------------------------------------------------------
         * Register Core Configuration
         * -------------------------------------------------------------------------
         */
        $configValues = [];
        if (file_exists($rootPath . '/etc/config.php')) {
            $configValues = require $rootPath . '/etc/config.php';
        }

        if (!is_dir($rootPath . '/vendor')) {
            throw new \RuntimeException("Vendor directory not found. Please run 'composer install'.");
        }



        /**
         * -------------------------------------------------------------------------
         * Register Directory Resolver
         * -------------------------------------------------------------------------
         *
         * Merge directories from three sources (last wins):
         * 1. Framework defaults (built into DirectoryResolver)
         * 2. User's Directories config class (src/Config/Directories.php)
         * 3. Inline $directories parameter passed to Kernel::create()
         */
        $configDirectories = self::loadDirectoriesConfig($rootPath);
        $mergedDirectories = array_merge($configDirectories, $directories);

        $directoryResolver = new DirectoryResolver($rootPath, $mergedDirectories);
        $container->singleton(DirectoryInterface::class, $directoryResolver);

        $container->singleton(Config::class, fn() => new Config($configValues, $directoryResolver->get('config')));

        /**
         * -------------------------------------------------------------------------
         * Kernel The Framework
         * -------------------------------------------------------------------------
         */
        $framework = new AppRegistry($container);
        $framework->build();

        /**
         * -------------------------------------------------------------------------
         * Create & Initialize The Application
         * -------------------------------------------------------------------------
         */
        $appClassName = $appClass ?? Application::class;

        if (!class_exists($appClassName)) {
            throw new \RuntimeException("Application class '$appClassName' not found.");
        }

        /** @var Application $app */
        $app = new $appClassName($container, $rootPath);
        $framework->init($app);

        return $app;
    }

    /**
     * Load user-defined directory config from src/Config/Directories.php if it exists.
     *
     * @return array<string, string>
     */
    private static function loadDirectoriesConfig(string $rootPath): array
    {
        $configFile = \Strux\Component\Config\DirectoryResolver::getDefaults($rootPath)['config'] . '/Directories.php';

        if (!file_exists($configFile)) {
            return [];
        }

        $returned = require $configFile;

        if (is_object($returned) && method_exists($returned, 'toArray')) {
            return $returned->toArray();
        }

        if (is_array($returned)) {
            return $returned;
        }

        // Try class-based approach
        if (class_exists('App\\Config\\Directories')) {
            $instance = new \App\Config\Directories();
            if (method_exists($instance, 'toArray')) {
                return $instance->toArray();
            }
        }

        return [];
    }
}
