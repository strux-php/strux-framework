<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class HiddenField extends FieldAttribute
{
    public function __construct(
        array   $attributes = [],
        mixed   $default = null
    )
    {
        parent::__construct(
            type: 'hidden',
            label: null,
            rules: [],
            attributes: $attributes,
            default: $default
        );
    }
}
