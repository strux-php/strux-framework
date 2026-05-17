<?php

declare(strict_types=1);

namespace Strux\Component\Form\Attributes;

use Attribute;
use ReflectionClass;

#[Attribute(Attribute::TARGET_PROPERTY)]
class FieldAttribute
{
    public function __construct(
        public string  $type = 'text',
        public ?string $label = null,
        public array   $rules = [],
        public array   $attributes = [],
        public array   $options = [],
        public mixed   $default = null,
        public ?string $coerce = null
    )
    {
    }

    public static function fromConfig(array $config): static
    {
        $ref = new ReflectionClass(static::class);
        $ctor = $ref->getConstructor();
        $params = $ctor ? $ctor->getParameters() : [];

        $args = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $config)) {
                $args[$name] = $config[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[$name] = $param->getDefaultValue();
            }
        }

        return new static(...$args);
    }

    public function toFieldConfig(): array
    {
        return [
            'label' => $this->label,
            'rules' => $this->rules,
            'attributes' => $this->attributes,
            'options' => $this->options,
            'coerce' => $this->coerce,
        ];
    }
}