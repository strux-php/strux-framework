<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Strux\Component\Scheduler\Attributes\RunWhen;
use Strux\Component\Scheduler\Attributes\Schedule;
use Strux\Component\Scheduler\Attributes\SendOutputTo;
use Strux\Component\Scheduler\Attributes\WithoutOverlapping;
use Strux\Support\ClassFinder;
use Throwable;

class Scheduler
{
    /** @var Task[] */
    private array $tasks = [];

    public function __construct(
        private ContainerInterface $container,
        private TaskRunner $runner,
        private CronParser $parser,
        private LoggerInterface $logger,
        private ?SchedulerConfig $config = null
    ) {
    }

    public function discover(?string $directory = null): void
    {
        $directories = $directory !== null ? [$directory] : [];
        if ($this->config !== null) {
            $directories = array_merge($directories, $this->config->getDirectories());
        }
        if (empty($directories)) {
            return;
        }

        foreach ($directories as $dir) {
            $this->discoverDirectory($dir);
        }
    }

    private function discoverDirectory(string $directory): void
    {
        $classes = ClassFinder::findClasses($directory);

        foreach ($classes as $className) {
            try {
                $reflection = new ReflectionClass($className);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $this->parseClass($reflection, $className);

            } catch (\ReflectionException) {
                continue;
            }
        }
    }

    private function parseClass(ReflectionClass $reflection, string $className): void
    {
        // Check class-level attributes
        $classScheduleAttr = $reflection->getAttributes(Schedule::class);
        if (!empty($classScheduleAttr)) {
            $this->buildTaskFromAttributes($reflection, $className, null);
        }

        // Check method-level attributes
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodScheduleAttr = $method->getAttributes(Schedule::class);
            if (!empty($methodScheduleAttr)) {
                $this->buildTaskFromAttributes($reflection, $className, $method->getName());
            }
        }
    }

    private function buildTaskFromAttributes(ReflectionClass $reflection, string $className, ?string $methodName): void
    {
        $target = $methodName !== null
            ? $reflection->getMethod($methodName)
            : $reflection;

        $scheduleAttr = $target->getAttributes(Schedule::class)[0]->newInstance();

        $expression = $scheduleAttr->getExpression();
        $this->parser->validate($expression);

        $withoutOverlapping = null;
        $woAttr = $target->getAttributes(WithoutOverlapping::class);
        if (!empty($woAttr)) {
            $withoutOverlapping = $woAttr[0]->newInstance();
        }

        $runWhens = [];
        foreach ($target->getAttributes(RunWhen::class) as $rwAttr) {
            $runWhens[] = $rwAttr->newInstance();
        }

        $sendOutputTo = null;
        $soAttr = $target->getAttributes(SendOutputTo::class);
        if (!empty($soAttr)) {
            $sendOutputTo = $soAttr[0]->newInstance();
        }

        $task = new Task(
            $className,
            $methodName,
            $scheduleAttr,
            $withoutOverlapping,
            $runWhens,
            $sendOutputTo,
        );

        $this->tasks[$task->getIdentifier()] = $task;
    }

    public function call(callable $callback): ScheduleBuilder
    {
        return new ScheduleBuilder($this, $callback, ScheduleBuilder::TYPE_CALLABLE);
    }

    public function command(string $command): ScheduleBuilder
    {
        return new ScheduleBuilder($this, $command, ScheduleBuilder::TYPE_COMMAND);
    }

    public function job(string $className): ScheduleBuilder
    {
        return new ScheduleBuilder($this, $className, ScheduleBuilder::TYPE_JOB);
    }

    public function addTask(Task $task): void
    {
        $this->tasks[$task->getIdentifier()] = $task;
    }

    public function runDueTasks(): void
    {
        if ($this->shouldSkipDueToMaintenance()) {
            $this->logger->info('Scheduler skipped: application is in maintenance mode.');
            return;
        }

        if ($this->shouldSkipDueToEnvironment()) {
            $this->logger->info('Scheduler skipped: current environment is not in allowed list.');
            return;
        }

        $now = new DateTimeImmutable('now');

        foreach ($this->tasks as $task) {
            try {
                $taskDate = $now;
                if ($task->schedule->timezone) {
                    try {
                        $timezone = new \DateTimeZone($task->schedule->timezone);
                        $taskDate = $now->setTimezone($timezone);
                    } catch (\Exception) {
                    }
                }

                if ($this->parser->isDue($task->schedule->getExpression(), $taskDate)) {
                    $this->runner->run($task);
                }
            } catch (Throwable $e) {
                $this->logger->error("Scheduler error for task {$task->getIdentifier()}: " . $e->getMessage());
            }
        }
    }

    private function shouldSkipDueToMaintenance(): bool
    {
        if ($this->config === null || !$this->config->skipInMaintenanceMode()) {
            return false;
        }
        try {
            $app = $this->container->get('config');
            return (bool) ($app->get('maintenance.active') ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    private function shouldSkipDueToEnvironment(): bool
    {
        if ($this->config === null) {
            return false;
        }
        $allowed = $this->config->getEnvironments();
        if (empty($allowed)) {
            return false;
        }
        $current = defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'production');
        return !in_array($current, $allowed, true);
    }

    public function getTasks(): array
    {
        return array_values($this->tasks);
    }
}
