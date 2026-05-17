<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class IntegerField extends FieldAttribute
{
    public function __construct(
        ?string $label = null,
        array   $rules = [],
        array   $attributes = [],
        mixed   $default = null
    )
    {
        $attributes['step'] = $attributes['step'] ?? '1';
        $attributes['inputmode'] = $attributes['inputmode'] ?? 'numeric';

        parent::__construct(
            type: 'number',
            label: $label,
            rules: $rules,
            attributes: $attributes,
            default: $default,
            coerce: 'int'
        );
    }
}
