<?php

declare(strict_types=1);

namespace Strux\Component\Validation\Rules;

class Min implements RulesInterface
{
    private float $min;
    private ?string $message;

    public function __construct(string $min, ?string $message = null)
    {
        $this->min = (float) $min;
        $this->message = $message ?? "The field must be at least {$this->min}.";
    }

    public function validate($value, $data = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (float) $value < $this->min) {
            return $this->message;
        }

        return null;
    }
}
