<?php

declare(strict_types=1);

namespace Strux\Component\Form;

use ReflectionClass;
use ReflectionProperty;
use Strux\Component\Form\Attributes\BooleanField;
use Strux\Component\Form\Attributes\DateField;
use Strux\Component\Form\Attributes\DateTimeLocalField;
use Strux\Component\Form\Attributes\DecimalField;
use Strux\Component\Form\Attributes\EmailField;
use Strux\Component\Form\Attributes\FieldAttribute;
use Strux\Component\Form\Attributes\IntegerField;
use Strux\Component\Form\Attributes\ListField;
use Strux\Component\Form\Attributes\PasswordField;
use Strux\Component\Form\Attributes\SearchField;
use Strux\Component\Form\Attributes\StringField;
use Strux\Component\Form\Attributes\TelField;
use Strux\Component\Form\Attributes\TextAreaField;
use Strux\Component\Form\Attributes\URLField;
use Strux\Component\Http\Request;
use Strux\Component\Database\ORM\Attributes\RelationAttribute;
use Strux\Component\Validation\Validator;
use Strux\Component\Validation\ValidatorInterface;
use Strux\Support\ContainerBridge;
use Strux\Support\Helpers\Utils;

abstract class Form implements FormInterface
{
	/** @var Field[] */
	protected array $fields = [];
	protected ValidatorInterface $validator;
	protected bool $isBound = false;

	protected string $action = '';
	protected string $method = 'POST';
	protected string $enctype = '';

	public static string $defaultWrapperClass = 'form-field';
	protected string $wrapperClass = '';

	/**
	 * Default CSS classes applied to all fields during render().
	 * Override in subclasses to theme forms without touching attributes.
	 */
	protected array $fieldClasses = [
		'label'    => 'form-label',
		'input'    => 'form-input',
		'error'    => 'form-error',
		'submit'   => 'btn-form',
		'checkbox' => 'form-checkbox',
	];

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
					'value' => $defaultValue,
					'coerce' => $instance->coerce,
				]);

				if ($defaultValue !== null && !$property->isReadOnly()) {
					$property->setAccessible(true);
					$property->setValue($this, $defaultValue);
				}
			}
		}
	}

	public function build(): void {}

	protected function add(string $name, string $type, array $config = []): self
	{
		$fieldType = $type;
		$fieldConfig = $config;

		if (is_subclass_of($type, FieldAttribute::class)) {
			$attribute = $type::fromConfig($config);
			$fieldType = $attribute->type;
			$fieldConfig = array_merge($attribute->toFieldConfig(), $config);
		}

		$this->fields[$name] = new Field($name, $fieldType, $fieldConfig);
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
					$value = $data->$name;
					if (is_scalar($value)) {
						$inputData[$name] = (string) $value;
					} elseif (is_array($value)) {
						$inputData[$name] = $value;
					} elseif ($value instanceof \DateTimeInterface) {
						$inputData[$name] = $value->format('Y-m-d\TH:i:s');
					}
				} elseif (method_exists($data, 'get' . ucfirst($name))) {
					// Try getter method (getName())
					$method = 'get' . ucfirst($name);
					$value = $data->$method();
					if (is_scalar($value)) {
						$inputData[$name] = (string) $value;
					} elseif (is_array($value)) {
						$inputData[$name] = $value;
					}
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
					$rp = new ReflectionProperty($this, $name);
					$propType = $rp->getType();
					$propTypeName = $propType instanceof \ReflectionNamedType ? $propType->getName() : null;

					// SAFETY CHECK: If value is null but property is strictly typed as 'string',
					// convert null back to empty string to prevent TypeError.
					if ($value === null) {
						if (
							$propTypeName === 'string'
							&& !$propType->allowsNull()
						) {
							$value = '';
						}
					}

					// SAFETY CHECK: If value is an array but property is typed as 'string',
					// join array into comma-separated string.
					if (is_array($value) && $propTypeName === 'string') {
						$value = implode(', ', $value);
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

	public function get(?string $key = null, mixed $default = null, ?string $type = null): mixed
	{
		// TODO: Needs fixing
		$data = $this->getData();
		if ($key === null) {
			return $data;
		}

		if (array_key_exists($key, $data)) {
			if ($type) {
				return Utils::castValue($data[$key], $type);
			} else {
				return $data[$key];
			}
		}

		if ($type) {
			return Utils::castValue($default, $type);
		} else {
			return $default;
		}
	}

	public function getErrors(): array
	{
		return $this->validator->getErrors();
	}

	/**
	 * Inject external errors (like ORM validation errors) into the form fields.
	 */
	public function addErrors(array $errors): self
	{
		foreach ($errors as $field => $messages) {
			if (isset($this->fields[$field])) {
				$this->fields[$field]->setErrors((array) $messages);
			}
		}
		return $this;
	}

	public function setAction(string $action): self
	{
		$this->action = $action;
		return $this;
	}

	public function setMethod(string $method): self
	{
		$this->method = strtoupper($method);
		return $this;
	}

	public function setEnctype(string $enctype): self
	{
		$this->enctype = $enctype;
		return $this;
	}

	public function setWrapperClass(string $class): self
	{
		$this->wrapperClass = $class;
		return $this;
	}

	public function create(object $model, array $options = []): self
	{
		if (isset($options['action'])) {
			$this->setAction($options['action']);
		}
		if (isset($options['method'])) {
			$this->setMethod($options['method']);
		}
		if (isset($options['enctype'])) {
			$this->setEnctype($options['enctype']);
		}
		if (isset($options['wrapperClass'])) {
			$this->setWrapperClass($options['wrapperClass']);
		}

		$this->fields = [];

		$reflection = new ReflectionClass($model);
		$properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

		foreach ($properties as $prop) {
			if ($prop->isStatic()) {
				continue;
			}

			if (!empty($prop->getAttributes(RelationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF))) {
				continue;
			}

			$type = $prop->getType();
			if (!$type instanceof \ReflectionNamedType) {
				continue;
			}

			$typeName = $type->getName();
			$name = $prop->getName();
			$fieldClass = $this->inferFieldType($name, $typeName);

			if ($fieldClass !== null) {
				$this->add($name, $fieldClass);
			}
		}

		$this->bind($model);

		return $this;
	}

	private function inferFieldType(string $name, string $phpType): ?string
	{
		$lower = strtolower($name);

		if ($phpType === 'string') {
			if (str_contains($lower, 'email')) {
				return EmailField::class;
			}
			if (str_contains($lower, 'password')) {
				return PasswordField::class;
			}
			if (str_contains($lower, 'description') || str_contains($lower, 'content') || str_contains($lower, 'body')) {
				return TextAreaField::class;
			}
			if (str_contains($lower, 'url') || str_contains($lower, 'website')) {
				return URLField::class;
			}
			if (str_contains($lower, 'phone') || str_contains($lower, 'tel')) {
				return TelField::class;
			}
			if (str_contains($lower, 'search') || str_contains($lower, 'query')) {
				return SearchField::class;
			}
			return StringField::class;
		}

		if ($phpType === 'int') {
			return IntegerField::class;
		}

		if ($phpType === 'float') {
			return DecimalField::class;
		}

		if ($phpType === 'bool') {
			return BooleanField::class;
		}

		if ($phpType === 'DateTime' || $phpType === 'DateTimeInterface' || $phpType === 'DateTimeImmutable') {
			if (str_ends_with($lower, '_at') || str_contains($lower, 'date')) {
				return DateTimeLocalField::class;
			}
			return DateTimeLocalField::class;
		}

		if ($phpType === 'array') {
			return ListField::class;
		}

		return null;
	}

	public function render(array $formAttributes = [], ?callable $layout = null): string
	{
		$wrapperClass = $this->wrapperClass !== '' ? $this->wrapperClass : static::$defaultWrapperClass;

		$formAttrs = array_merge([
			'action' => $this->action,
			'method' => $this->method === 'GET' ? 'GET' : 'POST',
		], $formAttributes);

		if ($this->enctype !== '') {
			$formAttrs['enctype'] = $this->enctype;
		}

		$formAttrString = $this->buildFormAttributes($formAttrs);

		$csrfHtml = '';
		if (function_exists('csrf_token')) {
			$csrfHtml = csrf_token();
		}

		if ($layout !== null) {
			$fieldsHtml = $layout($this->fields, $this);
		} else {
			$fieldsHtml = '';
			foreach ($this->fields as $field) {
				$fieldsHtml .= $this->renderField($field, $wrapperClass);
			}
		}

		return sprintf('<form %s>%s%s</form>', $formAttrString, $csrfHtml, $fieldsHtml);
	}

	private function renderField(Field $field, string $wrapperClass): string
	{
		$fieldType = $field->getType();

		if (in_array($fieldType, ['submit', 'button', 'reset'])) {
			$submitClass = $this->fieldClasses['submit'] ?? '';
			$inputAttrs = $field->getAttributes();
			if (isset($inputAttrs['class'])) {
				$submitClass = $inputAttrs['class'];
			}
			return sprintf(
				'<div class="%s pt-4">%s</div>',
				htmlspecialchars($wrapperClass),
				$field->input(['class' => $submitClass])
			);
		}

		$labelClass = $this->fieldClasses['label'] ?? '';
		$inputClass = $this->fieldClasses['input'] ?? '';
		$errorClass = $this->fieldClasses['error'] ?? '';

		$existingAttrs = $field->getAttributes();
		if (isset($existingAttrs['class'])) {
			$inputClass = $existingAttrs['class'];
		}

		$html = '';
		$html .= $field->label(['class' => $labelClass]);

		if ($fieldType === 'checkbox') {
			$html .= $field->input(['class' => $this->fieldClasses['checkbox'] ?? '']);
		} elseif ($fieldType === 'textarea') {
			$html .= $field->input(['class' => $inputClass . ' resize-none']);
		} elseif ($fieldType === 'select') {
			$html .= $field->input(['class' => $inputClass . ' appearance-none bg-[url("data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2216%22%20height%3D%2216%22%20fill%3D%22none%22%20stroke%3D%22%236b7280%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%224%206%208%2010%2012%206%22%2F%3E%3C%2Fsvg%3E")%20bg-no-repeat%20bg-right%20pr-10']);
		} else {
			$html .= $field->input(['class' => $inputClass]);
		}

		$html .= $field->error($errorClass);

		return sprintf(
			'<div class="%s">%s</div>',
			htmlspecialchars($wrapperClass),
			$html
		);
	}

	private function buildFormAttributes(array $attributes): string
	{
		$html = [];
		foreach ($attributes as $key => $value) {
			if ($value === true) {
				$html[] = $key;
			} elseif ($value !== false && $value !== null && $value !== '') {
				$html[] = sprintf('%s="%s"', $key, htmlspecialchars((string) $value));
			}
		}
		return implode(' ', $html);
	}
}
