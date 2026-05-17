<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ListField extends FieldAttribute
{
    public string $fieldType;
    public bool $multiple;

    public function __construct(
        array   $options = [],
        ?string $label = null,
        array   $rules = [],
        array   $attributes = [],
        mixed   $default = null,
        string  $fieldType = BooleanField::class,
        bool    $multiple = true,
    )
    {
        $this->fieldType = $fieldType;
        $this->multiple = $multiple;

        $attributes['multiple'] = $multiple;
        $attributes['fieldType'] = $fieldType;

        parent::__construct(
            type: 'list',
            label: $label,
            rules: $rules,
            attributes: $attributes,
            options: $options,
            default: $default,
            coerce: 'array',
        );
    }

    public function toFieldConfig(): array
    {
        return array_merge(parent::toFieldConfig(), [
            'fieldType' => $this->fieldType,
            'multiple' => $this->multiple,
        ]);
    }
}
