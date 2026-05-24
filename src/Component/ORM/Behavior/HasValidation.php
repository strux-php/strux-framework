<?php

declare(strict_types=1);

namespace Strux\Component\ORM\Behavior;

use Strux\Component\Validation\Validator;
use Strux\Component\Validation\ValidatorInterface;
use Strux\Support\ContainerBridge;
use Strux\Component\Exceptions\ValidationException;

trait HasValidation
{
    private array $_errors = [];

    /**
     * Define validation rules for the model.
     * Override this method in your models.
     */
    public function getRules(): array
    {
        $rules = [];

        if (property_exists($this, 'rules')) {
            $rules = $this->rules;
        }

        $reflection = new \ReflectionClass($this);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED) as $property) {
            $attributes = $property->getAttributes(\Strux\Component\ORM\Attributes\Validate::class);
            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $name = $property->getName();
                if (!isset($rules[$name])) {
                    $rules[$name] = [];
                }
                $rules[$name] = array_merge($rules[$name], $instance->rules);
            }
        }

        // Automatically ignore current record for unique rules during updates
        if (method_exists($this, 'exists') && $this->exists()) {
            $pkName = method_exists($this, 'getPrimaryKey') ? $this->getPrimaryKey() : 'id';
            $pkValue = $this->{$pkName} ?? null;
            
            if ($pkValue !== null) {
                foreach ($rules as $field => $fieldRules) {
                    $newFieldRules = [];
                    foreach ($fieldRules as $key => $rule) {
                        $ruleStr = is_string($key) ? $key : $rule;
                        
                        // Match unique[table, column] with exactly 2 parameters
                        if (is_string($ruleStr) && preg_match('/^unique\[\s*([^,]+)\s*,\s*([^,\]]+)\s*\]$/i', $ruleStr, $m)) {
                            $table = trim($m[1]);
                            $col = trim($m[2]);
                            $newRuleStr = "unique[{$table}, {$col}, {$pkValue}, {$pkName}]";
                            
                            if (is_string($key)) {
                                $newFieldRules[$newRuleStr] = $rule; // Preserve custom error message
                            } else {
                                $newFieldRules[] = $newRuleStr;
                            }
                        } else {
                            if (is_string($key)) {
                                $newFieldRules[$key] = $rule;
                            } else {
                                $newFieldRules[] = $rule;
                            }
                        }
                    }
                    $rules[$field] = $newFieldRules;
                }
            }
        }

        return $rules;
    }

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->_errors;
    }

    /**
     * Validate the model's attributes against its rules.
     *
     * @param bool $throw Whether to throw a ValidationException on failure
     * @return bool
     * @throws ValidationException
     */
    public function validate(bool $throw = false): bool
    {
        $rules = $this->getRules();
        if (empty($rules)) {
            $this->_errors = [];
            return true;
        }

        try {
            /** @var ValidatorInterface $validator */
            $validator = ContainerBridge::get(ValidatorInterface::class);
        } catch (\Throwable $e) {
            $validator = new Validator();
        }

        // Get properties to validate. Since we want to validate the DB properties:
        $data = method_exists($this, '_getPublicPropertiesForDb') 
            ? $this->_getPublicPropertiesForDb() 
            : (array) $this;

        if ($validator->validate($data, $rules)) {
            $this->_errors = [];
            return true;
        }

        $this->_errors = $validator->getErrors();

        if ($throw) {
            throw new ValidationException($this->_errors);
        }

        return false;
    }
}