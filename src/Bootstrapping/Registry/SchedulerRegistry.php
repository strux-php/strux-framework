<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Strux\Component\Cache\Cache;
use Strux\Component\Scheduler\CronParser;
use Strux\Component\Scheduler\Scheduler;
use Strux\Component\Scheduler\SchedulerConfig;
use Strux\Component\Scheduler\TaskRunner;

class SchedulerRegistry extends ServiceRegistry
{
    public function build(): void
    {
        $this->container->singleton(CronParser::class, CronParser::class);

        $this->container->singleton(TaskRunner::class, static function (ContainerInterface $c) {
            return new TaskRunner(
                $c,
                $c->get(EventDispatcherInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(Cache::class),
                $c->has('queue') ? $c->get('queue') : null,
            );
        });

        $this->container->singleton(Scheduler::class, static function (ContainerInterface $c) {
            $config = null;
            if ($c->has('config')) {
                $schedulerConfig = $c->get('config')->get('scheduler', []);
                $config = new SchedulerConfig($schedulerConfig);
            }
            return new Scheduler(
                $c,
                $c->get(TaskRunner::class),
                $c->get(CronParser::class),
                $c->get(LoggerInterface::class),
                $config,
            );
        });
    }
}
