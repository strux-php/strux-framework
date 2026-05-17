<?php

declare(strict_types=1);

namespace Strux\Component\Form;

use function in_array;
use function htmlspecialchars;
use function sprintf;
use function trim;
use function ucfirst;
use function str_replace;
use Strux\Component\Form\Attributes\FieldAttribute;

class Field
{
    protected string $name;
    protected string $type;
    protected ?string $label;
    protected mixed $value = null;
    protected array $attributes = [];
    protected array $rules = [];
    protected array $errors = [];
    protected array $options = [];
    protected ?string $coerce = null;
    protected ?string $fieldType = null;
    protected bool $multiple = false;

    public function __construct(string $name, string $type = 'text', array $config = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->label = $config['label'] ?? ucfirst(str_replace('_', ' ', $name));
        $this->value = $config['value'] ?? null;
        $this->attributes = $config['attributes'] ?? [];
        $this->rules = $config['rules'] ?? [];
        $this->options = $config['options'] ?? [];
        $this->coerce = $config['coerce'] ?? null;
        $this->fieldType = $config['fieldType'] ?? null;
        $this->multiple = !empty($config['multiple']);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): void
    {
        if ($this->type === 'list' && is_array($value)) {
            $value = array_values(array_filter($value, fn($v) => $v !== '' && $v !== null));
        }
        $this->value = $this->coerce ? $this->coerceValue($value) : $value;
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function setErrors(array $errors): void
    {
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasError(): bool
    {
        return !empty($this->errors);
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function setCoerce(?string $coerce): self
    {
        $this->coerce = $coerce;
        return $this;
    }

    public function getCoerce(): ?string
    {
        return $this->coerce;
    }

    protected function coerceValue(mixed $value): mixed
    {
        return match ($this->coerce) {
            'int' => $value === '' || $value === null ? null : (int) $value,
            'float' => $value === '' || $value === null ? null : (float) $value,
            'bool' => in_array($value, ['1', 'on', 'true', 'yes', 1, true], true),
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }

    public function label(array $attributes = []): string
    {
        if ($this->label === null || in_array($this->type, ['submit', 'button', 'reset', 'hidden'])) {
            return '';
        }

        $attrString = $this->buildAttributes(array_merge(['for' => $this->name], $attributes));
        return sprintf('<label %s>%s</label>', $attrString, htmlspecialchars($this->label));
    }

    public function input(array $attributes = []): string
    {
        $attributes = array_merge($this->attributes, $attributes);

        if ($this->hasError()) {
            $class = $attributes['class'] ?? '';
            $attributes['class'] = trim($class . ' is-invalid');
        }

        $isMultiple = !empty($attributes['multiple']);

        if ($this->type === 'checkbox') {
            $attributes['name'] = $this->name;
            return $this->renderCheckbox($attributes);
        }

        if ($this->type === 'radio') {
            $attributes['name'] = $this->name;
            return $this->renderRadio($attributes);
        }

        if ($this->type === 'textarea') {
            $attributes['name'] = $this->name;
            return $this->renderTextarea($attributes);
        }

        if ($this->type === 'select') {
            $attributes['name'] = $isMultiple ? $this->name . '[]' : $this->name;
            return $this->renderSelect($attributes);
        }

        if ($this->type === 'hidden') {
            $attributes['name'] = $this->name;
            return $this->renderHidden($attributes);
        }

        if ($this->type === 'list') {
            $attributes['name'] = $this->name;
            return $this->renderList($attributes);
        }

        $attributes['name'] = $isMultiple ? $this->name . '[]' : $this->name;
        $attributes['id'] = $this->name;

        if (in_array($this->type, ['submit', 'button', 'reset'])) {
            return $this->renderButton($attributes);
        }

        $attributes['type'] = $this->type;
        $attributes['value'] = (string) $this->value;

        return sprintf('<input %s>', $this->buildAttributes($attributes));
    }

    public function error(string $class = 'invalid-feedback'): string
    {
        if (!$this->hasError() || $this->type === 'hidden') {
            return '';
        }

        return sprintf('<div class="%s">%s</div>', $class, htmlspecialchars($this->errors[0]));
    }

    protected function renderTextarea(array $attributes): string
    {
        $value = $this->value;
        unset($attributes['value'], $attributes['type'], $attributes['multiple']);
        $attributes['id'] = $this->name;

        return sprintf(
            '<textarea %s>%s</textarea>',
            $this->buildAttributes($attributes),
            htmlspecialchars((string) $value)
        );
    }

    protected function renderSelect(array $attributes): string
    {
        $value = $this->value;
        unset($attributes['value'], $attributes['type']);
        $attributes['id'] = $this->name;

        $optionsHtml = '';
        foreach ($this->options as $val => $text) {
            $selected = is_array($value)
                ? in_array((string) $val, $value, true)
                : ($val == $value);
            $optionsHtml .= sprintf(
                '<option value="%s" %s>%s</option>',
                htmlspecialchars((string) $val),
                $selected ? 'selected' : '',
                htmlspecialchars($text)
            );
        }

        return sprintf('<select %s>%s</select>', $this->buildAttributes($attributes), $optionsHtml);
    }

    protected function renderButton(array $attributes): string
    {
        $text = $this->label ?? 'Submit';

        $type = $this->type;
        unset($attributes['type']);

        if ($this->value !== null) {
            $attributes['value'] = $this->value;
        }

        return sprintf(
            '<button type="%s" %s>%s</button>',
            $type,
            $this->buildAttributes($attributes),
            htmlspecialchars($text)
        );
    }

    protected function renderCheckbox(array $attributes): string
    {
        $checked = $this->value === true || $this->value === 1 || $this->value === '1' || $this->value === 'on';
        unset($attributes['type']);
        $attributes['value'] = '1';
        $attributes['id'] = $this->name;

        $name = $attributes['name'];
        $id = $attributes['id'] ?? $this->name;

        $hiddenInput = sprintf(
            '<input type="hidden" name="%s" value="0">',
            htmlspecialchars($name)
        );

        $checkboxInput = sprintf(
            '<input type="checkbox" %s %s>',
            $this->buildAttributes($attributes),
            $checked ? 'checked' : ''
        );

        return $hiddenInput . $checkboxInput;
    }

    protected function renderRadio(array $attributes): string
    {
        $currentValue = $this->value;
        unset($attributes['type']);

        $name = $attributes['name'];

        $html = '';
        foreach ($this->options as $optValue => $optLabel) {
            $optAttributes = $attributes;
            $optAttributes['value'] = (string) $optValue;
            $optAttributes['id'] = $name . '_' . $optValue;

            $selected = ((string) $optValue === (string) $currentValue);

            $html .= sprintf(
                '<label class="radio-option"><input type="radio" %s %s> <span>%s</span></label> ',
                $this->buildAttributes($optAttributes),
                $selected ? 'checked' : '',
                htmlspecialchars($optLabel)
            );
        }

        return $html;
    }

    protected function renderList(array $attributes): string
    {
        $currentValue = (array) $this->value;
        $subFqcn = $this->fieldType;
        $multiple = $this->multiple;

        $labelClass = $attributes['label_class'] ?? 'checkbox-option';
        $spanClass = !empty($attributes['span_class'])
            ? sprintf(' class="%s"', htmlspecialchars($attributes['span_class']))
            : '';
        unset($attributes['type'], $attributes['fieldType'], $attributes['multiple'], $attributes['label_class'], $attributes['span_class']);

        $subType = $this->resolveFieldType($subFqcn);
        $name = $attributes['name'];
        $html = '';

        if ($subType === 'checkbox') {
            $html .= sprintf(
                '<input type="hidden" name="%s[]" value="">',
                htmlspecialchars($name)
            );

            $inputName = $multiple ? $name . '[]' : $name;

            foreach ($this->options as $optValue => $optLabel) {
                $optAttributes = $attributes;
                $optAttributes['value'] = (string) $optValue;
                $optAttributes['id'] = $name . '_' . $optValue;

                $selected = in_array((string) $optValue, $currentValue, true);

                $html .= sprintf(
                    '<label class="%s"><input type="checkbox" name="%s" %s %s> <span%s>%s</span></label> ',
                    htmlspecialchars($labelClass),
                    htmlspecialchars($inputName),
                    $this->buildAttributes($optAttributes),
                    $selected ? 'checked' : '',
                    $spanClass,
                    htmlspecialchars($optLabel)
                );
            }
        }

        return $html;
    }

    protected function resolveFieldType(?string $fqcn): string
    {
        if ($fqcn === null) {
            return 'text';
        }

        if (is_subclass_of($fqcn, FieldAttribute::class)) {
            try {
                $ref = new \ReflectionClass($fqcn);
                $ctor = $ref->getConstructor();
                $defaults = [];
                if ($ctor) {
                    foreach ($ctor->getParameters() as $param) {
                        if ($param->isDefaultValueAvailable()) {
                            $defaults[$param->getName()] = $param->getDefaultValue();
                        }
                    }
                }
                $instance = new $fqcn(...$defaults);
                return $instance->type;
            } catch (\Throwable) {
                return 'text';
            }
        }

        return $fqcn;
    }

    protected function renderHidden(array $attributes): string
    {
        $attributes['type'] = 'hidden';
        $attributes['value'] = (string) $this->value;
        return sprintf('<input %s>', $this->buildAttributes($attributes));
    }

    protected function buildAttributes(array $attributes): string
    {
        $html = [];
        foreach ($attributes as $key => $value) {
            if ($value === true) {
                $html[] = $key;
            } elseif ($value !== false && $value !== null) {
                $html[] = sprintf('%s="%s"', $key, htmlspecialchars((string) $value));
            }
        }
        return implode(' ', $html);
    }
}
