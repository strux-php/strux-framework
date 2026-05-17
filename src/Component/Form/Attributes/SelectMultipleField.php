<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class SelectMultipleField extends FieldAttribute
{
    public function __construct(
        array   $options = [],
        ?string $label = null,
        array   $rules = [],
        array   $attributes = [],
        mixed   $default = null
    )
    {
        $attributes['multiple'] = true;

        parent::__construct(
            type: 'select',
            label: $label,
            rules: $rules,
            attributes: $attributes,
            options: $options,
            default: $default,
            coerce: 'array'
        );
    }
}
