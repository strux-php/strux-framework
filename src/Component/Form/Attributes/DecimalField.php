<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DecimalField extends FieldAttribute
{
    public function __construct(
        ?string $label = null,
        array   $rules = [],
        array   $attributes = [],
        mixed   $default = null
    )
    {
        $attributes['step'] = $attributes['step'] ?? '0.01';
        $attributes['inputmode'] = $attributes['inputmode'] ?? 'decimal';

        parent::__construct(
            type: 'text',
            label: $label,
            rules: $rules,
            attributes: $attributes,
            default: $default,
            coerce: 'float'
        );
    }
}
