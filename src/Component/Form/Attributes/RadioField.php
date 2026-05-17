<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class RadioField extends FieldAttribute
{
    public function __construct(
        array   $options = [],
        ?string $label = null,
        array   $rules = [],
        array   $attributes = [],
        mixed   $default = null
    )
    {
        parent::__construct(
            type: 'radio',
            label: $label,
            rules: $rules,
            attributes: $attributes,
            options: $options,
            default: $default
        );
    }
}
