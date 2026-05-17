<?php

declare(strict_types=1);

namespace Strux\Component\Model\Behavior;

use DateTime;
use Exception;
use JsonException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use Strux\Component\Database\Attributes\Column;
use Strux\Component\Database\Attributes\SoftDelete;
use Strux\Component\Model\Attributes\Hidden;
use Strux\Component\Model\Attributes\RelationAttribute;

trait HasAttributes
{
    private array $_original = [];
    private array $_hiddenOverrides = [];

    /**
     * @throws ReflectionException
     */
    public function fill(array $attributes): static
    {
        static $propertyMapCache = [];
        $class = static::class;

        if (!isset($propertyMapCache[$class])) {
            $reflection = new ReflectionClass($class);
            $map = [];
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                if (!$prop->isStatic() && !$prop->isProtected()) {
                    if (!empty($prop->getAttributes(RelationAttribute::class, ReflectionAttribute::IS_INSTANCEOF))) {
                        continue;
                    }

                    $type = $prop->getType() instanceof ReflectionNamedType ? $prop->getType()->getName() : null;
                    $propName = $prop->getName();

                    $colAttr = $prop->getAttributes(Column::class);
                    $dbName = $propName;
                    if (!empty($colAttr)) {
                        $colInstance = $colAttr[0]->newInstance();
                        if ($colInstance->name) {
                            $dbName = $colInstance->name;
                        }
                    }

                    $info = ['name' => $propName, 'type' => $type];
                    $map[strtolower($propName)] = $info;
                    if ($dbName !== $propName) {
                        $map[strtolower($dbName)] = $info;
                    }
                }
            }
            $propertyMapCache[$class] = $map;
        }

        $propertyMap = $propertyMapCache[$class];

        foreach ($attributes as $key => $value) {
            $lowerKey = strtolower($key);

            if (isset($propertyMap[$lowerKey])) {
                $propInfo = $propertyMap[$lowerKey];
                $casedPropertyName = $propInfo['name'];
                $propertyType = $propInfo['type'];

                try {
                    $castedValue = $this->castAttribute($value, $propertyType);
                    $this->{$casedPropertyName} = $castedValue;
                } catch (Exception $e) {
                    throw new RuntimeException("Failed to cast attribute '$key'.", 0, $e);
                }
            }
        }
        return $this;
    }

    /**
     * @throws Exception
     */
    protected function castAttribute(mixed $value, ?string $type): mixed
    {
        if ($value === null || $type === null) {
            return $value;
        }

        return match ($type) {
            'int' => (int)$value,
            'bool' => (bool)$value,
            'float' => (float)$value,
            'string' => (string)$value,
            'array' => is_string($value) ? json_decode($value, true) : $value,
            'DateTime', '\\DateTime' => is_string($value) ? new DateTime($value) : $value,
            default => $value
        };
    }

    public function getAttribute(string $key)
    {
        if (property_exists($this, $key)) {
            $reflectionProperty = new ReflectionProperty($this, $key);
            if ($reflectionProperty->isPublic()) {
                return $this->{$key};
            }
        }

        if (array_key_exists($key, $this->_original)) {
            return $this->_original[$key];
        }

        return null;
    }

    public function hide(array $fields, ?callable $condition = null): static
    {
        if ($condition !== null && !$condition()) {
            return $this;
        }
        foreach ($fields as $field) {
            $this->_hiddenOverrides[$field] = true;
        }
        return $this;
    }

    public function unhide(array $fields, ?callable $condition = null): static
    {
        if ($condition !== null && !$condition()) {
            return $this;
        }
        foreach ($fields as $field) {
            $this->_hiddenOverrides[$field] = false;
        }
        return $this;
    }

    private function _isHidden(string $property): bool
    {
        if (array_key_exists($property, $this->_hiddenOverrides)) {
            return $this->_hiddenOverrides[$property];
        }
        $reflection = new ReflectionClass($this);
        if (!$reflection->hasProperty($property)) {
            return false;
        }
        $prop = $reflection->getProperty($property);
        if ($prop->isProtected() || $prop->isPrivate()) {
            return false;
        }
        return !empty($prop->getAttributes(Hidden::class));
    }

    private function _getPublicPropertiesForDb(): array
    {
        $reflection = new ReflectionClass($this);
        $data = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!empty($prop->getAttributes(RelationAttribute::class, ReflectionAttribute::IS_INSTANCEOF))) {
                continue;
            }

            if (!$prop->isStatic() && !$prop->isProtected() && $prop->isInitialized($this)) {
                $dbName = $prop->getName();
                $colAttr = $prop->getAttributes(Column::class);
                if (!empty($colAttr)) {
                    $colInstance = $colAttr[0]->newInstance();
                    if ($colInstance->name) {
                        $dbName = $colInstance->name;
                    }
                }
                $data[$dbName] = $this->{$prop->getName()};
            }
        }
        return $data;
    }

    public function getSoftDeleteColumn(): string
    {
        $attributes = $this->reflection()->getAttributes(SoftDelete::class);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance()->column;
        }
        return 'deleted_at';
    }

    public function toArray(): array
    {
        if (isset($this->_isQueryBuilderInstance) && $this->_isQueryBuilderInstance) {
            $collection = $this->get();
            return array_map(fn($item) => $item->toArray(), $collection->all());
        }
        $data = $this->_getPublicPropertiesForDb();
        foreach (array_keys($data) as $key) {
            if ($this->_isHidden($key)) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    /**
     * @throws JsonException
     */
    public function toJson(int $options = 0): string
    {
        $data = $this->toArray();
        return json_encode($data, JSON_THROW_ON_ERROR | $options);
    }
}