<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class IntegerRangeField extends FieldAttribute
{
    public function __construct(
        ?string $label = null,
        array   $rules = [],
        array   $attributes = [],
        mixed   $default = null
    )
    {
        $attributes['step'] = $attributes['step'] ?? '1';

        parent::__construct(
            type: 'range',
            label: $label,
            rules: $rules,
            attributes: $attributes,
            default: $default,
            coerce: 'int'
        );
    }
}
