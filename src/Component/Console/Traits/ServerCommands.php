<?php

declare(strict_types=1);

namespace Strux\Component\Console\Traits;

use Exception;

trait ServerCommands
{
    private function linkStorage(): void
    {
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 4);
        
        $publicDir = $this->container->has(\Strux\Component\Config\DirectoryInterface::class) 
            ? $this->container->get(\Strux\Component\Config\DirectoryInterface::class)->get('public')
            : \Strux\Component\Config\DirectoryResolver::getDefaults($rootPath)['public'];

        $publicPath = $publicDir . '/storage';
        $storagePath = $rootPath . '/storage/app/web';

        if (file_exists($publicPath) || is_link($publicPath)) {
            echo "The [" . basename($publicDir) . "/storage] link already exists.\n";
            return;
        }
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
        try {
            symlink($storagePath, $publicPath);
            echo "The [" . basename($publicDir) . "/storage] directory has been linked.\n";
        } catch (Exception $e) {
            echo "Error creating symlink: " . $e->getMessage() . "\n";
        }
    }

    private function unlinkStorage(): void
    {
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 4);
        
        $publicDir = $this->container->has(\Strux\Component\Config\DirectoryInterface::class) 
            ? $this->container->get(\Strux\Component\Config\DirectoryInterface::class)->get('public')
            : \Strux\Component\Config\DirectoryResolver::getDefaults($rootPath)['public'];

        $publicPath = $publicDir . '/storage';

        if (!file_exists($publicPath) && !is_link($publicPath)) {
            echo "The [" . basename($publicDir) . "/storage] link does not exist.\n";
            return;
        }

        try {
            if (is_link($publicPath)) {
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    rmdir($publicPath);
                } else {
                    unlink($publicPath);
                }
            } else {
                echo "Error: [" . basename($publicDir) . "/storage] is a directory, not a symlink. Manual removal required.\n";
                return;
            }

            echo "The [" . basename($publicDir) . "/storage] link has been removed.\n";
        } catch (Exception $e) {
            echo "Error removing symlink: " . $e->getMessage() . "\n";
        }
    }
}