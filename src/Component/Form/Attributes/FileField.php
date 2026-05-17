<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class FileField extends FieldAttribute
{
    public function __construct(
        ?string $label = null,
        array   $rules = [],
        array   $attributes = [],
        mixed   $default = null
    )
    {
        parent::__construct(
            type: 'file',
            label: $label,
            rules: $rules,
            attributes: $attributes,
            default: $default
        );
    }
}
