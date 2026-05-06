<?php

declare(strict_types=1);

namespace Strux\Component\Form;

use ReflectionClass;
use ReflectionProperty;
use Strux\Component\Form\Attributes\FieldAttribute;
use Strux\Component\Http\Request;
use Strux\Component\Validation\Validator;
use Strux\Component\Validation\ValidatorInterface;
use Strux\Support\ContainerBridge;

abstract class Form implements FormInterface
{
    /** @var Field[] */
    protected array $fields = [];
    protected ValidatorInterface $validator;
    protected bool $isBound = false;

    /**
     * @param mixed $data Can be a Request object, an Array, or a Model/Entity object.
     * @param ValidatorInterface|null $validator
     */
    public function __construct(mixed $data = null, ?ValidatorInterface $validator = null)
    {
        // 1. Resolve Validator
        if ($validator) {
            $this->validator = $validator;
        } else {
            try {
                $this->validator = ContainerBridge::get(ValidatorInterface::class);
            } catch (\Throwable $e) {
                $this->validator = new Validator();
            }
        }

        // 2. Build Fields
        $this->parseAttributes();
        $this->build();

        // 3. Auto-bind if data is provided
        if ($data !== null) {
            $this->bind($data);
        }
    }

    protected function parseAttributes(): void
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);

        foreach ($properties as $property) {
            $attributes = $property->getAttributes(FieldAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attribute) {
                /** @var FieldAttribute $instance */
                $instance = $attribute->newInstance();

                $defaultValue = $instance->default;
                if ($property->isInitialized($this) && $property->getValue($this) !== null) {
                    $defaultValue = $property->getValue($this);
                }

                $this->add($property->getName(), $instance->type, [
                    'label' => $instance->label,
                    'rules' => $instance->rules,
                    'attributes' => $instance->attributes,
                    'options' => $instance->options,
                    'value' => $defaultValue
                ]);

                if ($defaultValue !== null && !$property->isReadOnly()) {
                    $property->setAccessible(true);
                    $property->setValue($this, $defaultValue);
                }
            }
        }
    }

    public function build(): void
    {
    }

    protected function add(string $name, string $type = 'text', array $options = []): self
    {
        $this->fields[$name] = new Field($name, $type, $options);
        return $this;
    }

    public function field(?string $name = null): Field
    {
        if (isset($this->fields[$name])) {
            return $this->fields[$name];
        }
        throw new \InvalidArgumentException("Field '$name' does not exist.");
    }

    public function __get(string $name)
    {
        if (isset($this->fields[$name])) {
            return $this->fields[$name];
        }
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        throw new \InvalidArgumentException("Field or property '$name' does not exist.");
    }

    /**
     * Smart Bind: Handles Requests, Arrays, and Objects.
     */
    public function bind(mixed $data): self
    {
        $inputData = [];

        // 1. Determine Source Type
        if ($data instanceof Request) {
            // Handle HTTP Request
            $inputData = $data->all();
        } elseif (is_array($data)) {
            // Handle Plain Array
            $inputData = $data;
        } elseif (is_object($data)) {
            // Handle Model/Entity Object
            // Convert object properties to array for binding
            // We only care about properties that match our form fields
            foreach ($this->fields as $name => $field) {
                if (property_exists($data, $name)) {
                    // Access public property directly
                    $inputData[$name] = (string) $data->$name;
                } elseif (method_exists($data, 'get' . ucfirst($name))) {
                    // Try getter method (getName())
                    $method = 'get' . ucfirst($name);
                    $inputData[$name] = (string) $data->$method();
                } elseif (method_exists($data, 'toArray')) {
                    // Fallback to toArray() if available
                    $arrayData = $data->toArray();
                    if (isset($arrayData[$name])) {
                        $inputData[$name] = (string) $arrayData[$name];
                    }
                }
            }
        }

        // 2. Bind Data to Fields and Properties
        foreach ($this->fields as $name => $field) {
            if (array_key_exists($name, $inputData)) {
                $value = $inputData[$name];

                // Update Field Object
                $field->setValue($value);

                // Update Class Property
                if (property_exists($this, $name)) {

                    // SAFETY CHECK: If value is null but property is strictly typed as 'string',
                    // convert null back to empty string to prevent TypeError.
                    if ($value === null) {
                        $rp = new ReflectionProperty($this, $name);
                        $type = $rp->getType();

                        // Check if type is 'string' and does NOT allow nulls
                        if (
                            $type instanceof \ReflectionNamedType
                            && $type->getName() === 'string'
                            && !$type->allowsNull()
                        ) {
                            $value = '';
                        }
                    }

                    $this->$name = $value;
                }
            }
        }

        $this->isBound = true;
        return $this;
    }

    public function isValid(): bool
    {
        // Allow validation only if bound
        if (!$this->isBound)
            return false;

        $data = [];
        $rules = [];

        foreach ($this->fields as $name => $field) {
            $data[$name] = $field->getValue();
            $fieldRules = $field->getRules();
            if (!empty($fieldRules)) {
                $rules[$name] = $fieldRules;
            }
        }

        if ($this->validator->validate($data, $rules)) {
            return true;
        }

        $errors = $this->validator->getErrors();
        foreach ($errors as $field => $messages) {
            if (isset($this->fields[$field])) {
                $this->fields[$field]->setErrors((array) $messages);
            }
        }

        return false;
    }

    public function getData(): array
    {
        $data = [];
        foreach ($this->fields as $name => $field) {
            $data[$name] = $field->getValue();
        }
        return $data;
    }

    public function getErrors(): array
    {
        return $this->validator->getErrors();
    }
}