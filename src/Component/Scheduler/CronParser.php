<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler;

use DateTimeInterface;
use DateTimeImmutable;
use InvalidArgumentException;

class CronParser
{
    private const FREQUENCIES = [
        'yearly' => '0 0 1 1 *',
        'annually' => '0 0 1 1 *',
        'monthly' => '0 0 1 * *',
        'weekly' => '0 0 * * 0',
        'daily' => '0 0 * * *',
        'hourly' => '0 * * * *',
        'everyminute' => '* * * * *',
        'everytwominutes' => '*/2 * * * *',
        'everythreeminutes' => '*/3 * * * *',
        'everyfourminutes' => '*/4 * * * *',
        'everyfiveminutes' => '*/5 * * * *',
        'everytenminutes' => '*/10 * * * *',
        'everyfifteenminutes' => '*/15 * * * *',
        'everythirtyminutes' => '0,30 * * * *',
        'weekdays' => '* * * * 1-5',
        'weekends' => '* * * * 0,6',
    ];

    private const FIELD_RANGES = [
        [0, 59],  // minute
        [0, 23],  // hour
        [1, 31],  // day of month
        [1, 12],  // month
        [0, 6],   // day of week
    ];

    public function validate(string $expression): void
    {
        $resolved = $this->resolveExpression($expression);
        $parts = preg_split('/\s+/', trim($resolved));

        if (count($parts) !== 5) {
            throw new InvalidArgumentException(
                "Invalid cron expression '{$expression}': expected 5 fields, got " . count($parts)
            );
        }

        foreach ($parts as $index => $part) {
            $this->validateField($part, $index, $expression);
        }
    }

    private function validateField(string $part, int $index, string $originalExpression): void
    {
        [$min, $max] = self::FIELD_RANGES[$index];

        $part = trim($part);

        if ($part === '*') {
            return;
        }

        // Handle step: */5, 1-30/5
        if (str_contains($part, '/')) {
            [$range, $step] = explode('/', $part, 2);
            if (!ctype_digit(ltrim($step, '-'))) {
                throw new InvalidArgumentException(
                    "Invalid cron expression '{$originalExpression}': invalid step value '{$step}'"
                );
            }
            if ($range !== '*') {
                $this->validateFieldRange($range, $min, $max, $originalExpression);
            }
            return;
        }

        // Handle list: 1,3,5
        if (str_contains($part, ',')) {
            foreach (explode(',', $part) as $item) {
                $this->validateField($item, $index, $originalExpression);
            }
            return;
        }

        // Handle range: 1-5
        if (str_contains($part, '-')) {
            $this->validateFieldRange($part, $min, $max, $originalExpression);
            return;
        }

        // Single value
        if (!ctype_digit(ltrim($part, '-'))) {
            throw new InvalidArgumentException(
                "Invalid cron expression '{$originalExpression}': non-numeric value '{$part}'"
            );
        }

        $value = (int) $part;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(
                "Invalid cron expression '{$originalExpression}': value {$value} out of range [{$min}, {$max}] for field {$index}"
            );
        }
    }

    private function validateFieldRange(string $range, int $min, int $max, string $originalExpression): void
    {
        if (!str_contains($range, '-')) {
            throw new InvalidArgumentException(
                "Invalid cron expression '{$originalExpression}': expected range format start-end, got '{$range}'"
            );
        }
        [$start, $end] = explode('-', $range, 2);
        if (!ctype_digit(ltrim($start, '-')) || !ctype_digit(ltrim($end, '-'))) {
            throw new InvalidArgumentException(
                "Invalid cron expression '{$originalExpression}': non-numeric range values '{$range}'"
            );
        }
        $s = (int) $start;
        $e = (int) $end;
        if ($s < $min || $s > $max || $e < $min || $e > $max) {
            throw new InvalidArgumentException(
                "Invalid cron expression '{$originalExpression}': range [{$start}, {$end}] out of bounds [{$min}, {$max}]"
            );
        }
        if ($s > $e) {
            throw new InvalidArgumentException(
                "Invalid cron expression '{$originalExpression}': range start {$start} > end {$end}"
            );
        }
    }

    public function isDue(string $expression, ?DateTimeInterface $date = null): bool
    {
        $date = $date ?? new DateTimeImmutable('now');
        $expression = $this->resolveExpression($expression);
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            throw new InvalidArgumentException("Invalid cron expression: {$expression}");
        }

        $currentTime = [
            (int) $date->format('i'), // Minute (0-59)
            (int) $date->format('H'), // Hour (0-23)
            (int) $date->format('d'), // Day of month (1-31)
            (int) $date->format('n'), // Month (1-12)
            (int) $date->format('w'), // Day of week (0-6) (Sunday is 0)
        ];

        foreach ($parts as $index => $part) {
            if (!$this->matchPart($part, $currentTime[$index])) {
                return false;
            }
        }

        return true;
    }

    private function resolveExpression(string $expression): string
    {
        $expression = strtolower(trim($expression));
        if (isset(self::FREQUENCIES[$expression])) {
            return self::FREQUENCIES[$expression];
        }
        return $expression;
    }

    private function matchPart(string $part, int $currentValue): bool
    {
        if ($part === '*') {
            return true;
        }

        // Handle step values like */5 or 10-30/5
        if (str_contains($part, '/')) {
            [$range, $step] = explode('/', $part, 2);
            $step = (int) $step;

            if ($range === '*') {
                return $currentValue % $step === 0;
            }

            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                return $currentValue >= (int)$start && $currentValue <= (int)$end && ($currentValue - (int)$start) % $step === 0;
            }
        }

        // Handle lists like 1,3,5
        if (str_contains($part, ',')) {
            $list = explode(',', $part);
            foreach ($list as $item) {
                if ($this->matchPart($item, $currentValue)) {
                    return true;
                }
            }
            return false;
        }

        // Handle ranges like 1-5
        if (str_contains($part, '-')) {
            [$start, $end] = explode('-', $part, 2);
            return $currentValue >= (int) $start && $currentValue <= (int) $end;
        }

        // Direct match
        return $currentValue === (int) $part;
    }
}
