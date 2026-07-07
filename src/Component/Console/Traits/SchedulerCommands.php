<?php

declare(strict_types=1);

namespace Strux\Component\Console\Traits;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Strux\Component\Console\Output;
use Strux\Component\Scheduler\Scheduler;
use Throwable;

trait SchedulerCommands
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function runSchedule(): void
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->container->get(Scheduler::class);

        $this->discoverSchedulerDirectories($scheduler);

        $scheduler->runDueTasks();
        Output::info("Scheduled tasks executed.");
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function workSchedule(): void
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->container->get(Scheduler::class);

        $this->discoverSchedulerDirectories($scheduler);

        Output::info("Scheduler worker started. Press Ctrl+C to stop.");

        $running = true;

        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                Output::warning("\nShutdown signal received. Exiting gracefully after current run...");
                $running = false;
            });
            pcntl_signal(SIGTERM, function () use (&$running) {
                Output::warning("\nTermination signal received. Exiting gracefully after current run...");
                $running = false;
            });
        }

        while ($running) {
            try {
                $scheduler->runDueTasks();
            } catch (Throwable $e) {
                Output::error("Scheduler worker error: " . $e->getMessage());
            }

            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }

            // Break sleep into 1-second intervals for signal responsiveness
            $now = time();
            $sleepTime = 60 - ($now % 60);
            for ($i = 0; $i < $sleepTime && $running; $i++) {
                sleep(1);
                if (extension_loaded('pcntl')) {
                    pcntl_signal_dispatch();
                }
            }
        }

        Output::info("Scheduler worker stopped gracefully.");
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function discoverSchedulerDirectories(Scheduler $scheduler): void
    {
        // Always discover the app's src directory
        $scheduler->discover($this->rootPath . '/src');
    }
}
