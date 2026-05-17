<?php

declare(strict_types=1);

namespace Strux\Component\Validation\Rules;

class Boolean implements RulesInterface
{
    private ?string $message;

    public function __construct(?string $message = null)
    {
        $this->message = $message ?? 'The field must be a boolean.';
    }

    public function validate($value, $data = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $valid = [true, false, 0, 1, '0', '1', 'on', 'off', 'true', 'false', 'yes', 'no'];

        if (!in_array($value, $valid, true)) {
            return $this->message;
        }

        return null;
    }
}
