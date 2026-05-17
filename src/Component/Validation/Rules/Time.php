<?php

declare(strict_types=1);

namespace Strux\Component\Validation\Rules;

class Time implements RulesInterface
{
    private ?string $message;

    public function __construct(?string $message = null)
    {
        $this->message = $message ?? 'The field must be a valid time in HH:MM format.';
    }

    public function validate($value, $data = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $value)) {
            return $this->message;
        }

        return null;
    }
}
