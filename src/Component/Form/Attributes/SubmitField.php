<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class SubmitField extends FieldAttribute
{
    public function __construct(
        ?string $label = 'Submit',
        array   $attributes = []
    )
    {
        if (!isset($attributes['class'])) {
            $attributes['class'] = 'btn btn-primary';
        }

        parent::__construct(
            type: 'submit',
            label: $label,
            attributes: $attributes
        );
    }
}
