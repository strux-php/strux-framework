<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler;

use Closure;
use Strux\Component\Scheduler\Attributes\Schedule;
use Strux\Component\Scheduler\Attributes\SendOutputTo;
use Strux\Component\Scheduler\Attributes\WithoutOverlapping;

class ScheduleBuilder
{
    public const TYPE_CALLABLE = 'callable';
    public const TYPE_COMMAND = 'command';
    public const TYPE_JOB = 'job';

    private ?string $expression = null;
    private ?string $frequency = null;
    private ?string $timezone = null;
    private ?int $withoutOverlappingExpiry = null;
    /** @var callable[] */
    private array $conditions = [];
    private ?string $outputPath = null;
    private bool $appendOutput = false;

    public function __construct(
        private Scheduler $scheduler,
        private mixed $target,
        private string $type
    ) {
    }

    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    public function everyMinute(): self
    {
        return $this->setFrequency('everyminute');
    }

    public function everyTwoMinutes(): self
    {
        return $this->setFrequency('everytwominutes');
    }

    public function everyThreeMinutes(): self
    {
        return $this->setFrequency('everythreeminutes');
    }

    public function everyFourMinutes(): self
    {
        return $this->setFrequency('everyfourminutes');
    }

    public function everyFiveMinutes(): self
    {
        return $this->setFrequency('everyfiveminutes');
    }

    public function everyTenMinutes(): self
    {
        return $this->setFrequency('everytenminutes');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->setFrequency('everyfifteenminutes');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->setFrequency('everythirtyminutes');
    }

    public function hourly(): self
    {
        return $this->setFrequency('hourly');
    }

    public function daily(): self
    {
        return $this->setFrequency('daily');
    }

    public function weekly(): self
    {
        return $this->setFrequency('weekly');
    }

    public function monthly(): self
    {
        return $this->setFrequency('monthly');
    }

    public function yearly(): self
    {
        return $this->setFrequency('yearly');
    }

    public function weekdays(): self
    {
        return $this->setFrequency('weekdays');
    }

    public function weekends(): self
    {
        return $this->setFrequency('weekends');
    }

    public function at(string $time): self
    {
        if ($this->frequency === 'daily') {
            [$hour, $minute] = explode(':', $time) + [1 => '0'];
            $this->expression = sprintf('%s %s * * *', (int)$minute, (int)$hour);
        }
        return $this;
    }

    public function timezone(string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function withoutOverlapping(int $expiresAfterMinutes = 1440): self
    {
        $this->withoutOverlappingExpiry = $expiresAfterMinutes;
        return $this;
    }

    public function when(callable $condition): self
    {
        $this->conditions[] = $condition;
        return $this;
    }

    public function sendOutputTo(string $path, bool $append = false): self
    {
        $this->outputPath = $path;
        $this->appendOutput = $append;
        return $this;
    }

    public function appendOutputTo(string $path): self
    {
        return $this->sendOutputTo($path, true);
    }

    private function setFrequency(string $frequency): self
    {
        $this->frequency = $frequency;
        return $this;
    }

    public function register(): Task
    {
        $scheduleAttr = new Schedule(
            expression: $this->expression,
            frequency: $this->frequency,
            timezone: $this->timezone,
        );

        $withoutOverlappingAttr = $this->withoutOverlappingExpiry !== null
            ? new WithoutOverlapping($this->withoutOverlappingExpiry)
            : null;

        $sendOutputToAttr = $this->outputPath !== null
            ? new SendOutputTo($this->outputPath, $this->appendOutput)
            : null;

        $runWhens = [];
        foreach ($this->conditions as $condition) {
            $runWhens[] = new Attributes\RunWhen($condition);
        }

        $className = $this->resolveClassName();
        $task = new Task(
            $className,
            null,
            $scheduleAttr,
            $withoutOverlappingAttr,
            $runWhens,
            $sendOutputToAttr,
        );

        $this->scheduler->addTask($task);
        return $task;
    }

    private function resolveClassName(): string
    {
        return match ($this->type) {
            self::TYPE_CALLABLE => 'Closure',
            self::TYPE_COMMAND => 'Command',
            self::TYPE_JOB => is_string($this->target) ? $this->target : 'Job',
            default => 'Unknown',
        };
    }

    public function getTarget(): mixed
    {
        return $this->target;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
