<?php

declare(strict_types=1);

namespace Strux\Component\Validation\Rules;

class Max implements RulesInterface
{
    private float $max;
    private ?string $message;

    public function __construct(string $max, ?string $message = null)
    {
        $this->max = (float) $max;
        $this->message = $message ?? "The field must be at most {$this->max}.";
    }

    public function validate($value, $data = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (float) $value > $this->max) {
            return $this->message;
        }

        return null;
    }
}
