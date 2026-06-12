<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Behavior;

use DateTime;
use Exception;
use JsonException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use Strux\Component\Database\Schema\Attributes\Column;
use Strux\Component\Database\Schema\Attributes\SoftDelete;
use Strux\Component\Database\ORM\Attributes\Hidden;
use Strux\Component\Database\ORM\Attributes\RelationAttribute;
use Strux\Component\Database\ORM\Attributes\Transform;
use Strux\Component\Database\ORM\Attributes\Reformat;
use Strux\Component\Database\ORM\Enums\DataType;

trait HasAttributes
{
    private array $_original = [];
    private array $_hiddenOverrides = [];

    /**
     * @throws ReflectionException
     */
    public function fill(array $attributes): static
    {
        $entityAttr = $this->reflection()->getAttributes(\Strux\Component\Database\Schema\Attributes\Entity::class)[0] ?? null;
        if ($entityAttr && $entityAttr->newInstance()->mapper !== null) {
            $mapperClass = $entityAttr->newInstance()->mapper;
            if (class_exists($mapperClass)) {
                $mapper = new $mapperClass();
                if (method_exists($mapper, 'map')) {
                    $mapper->map($attributes, $this);
                    return $this;
                }
            }
        }

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
                    if (empty($colAttr)) {
                        continue;
                    }
                    $dbName = $propName;
                    $colInstance = $colAttr[0]->newInstance();
                    if ($colInstance->name) {
                        $dbName = $colInstance->name;
                    }

                    $transformAttr = $prop->getAttributes(Transform::class);
                    $reformatAttr = $prop->getAttributes(Reformat::class);

                    $info = [
                        'name' => $propName, 
                        'type' => $type,
                        'transform' => !empty($transformAttr) ? $transformAttr[0]->newInstance() : null,
                        'reformat' => !empty($reformatAttr) ? $reformatAttr[0]->newInstance() : null,
                    ];
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
                $transform = $propInfo['transform'];
                $reformat = $propInfo['reformat'];

                try {
                    $castedValue = $value;
                    if ($reformat && $reformat->get) {
                        $method = $reformat->get;
                        if (method_exists($this, $method)) {
                            $castedValue = $this->{$method}($value);
                        } elseif (is_callable($method)) {
                            $castedValue = $method($value);
                        } else {
                            $castedValue = $this->transformAttributeOnRead($value, $transform ? $transform->type : null, $propertyType);
                        }
                    } else {
                        $castedValue = $this->transformAttributeOnRead($value, $transform ? $transform->type : null, $propertyType);
                    }
                    
                    $this->{$casedPropertyName} = $castedValue;
                } catch (Exception $e) {
                    throw new RuntimeException("Failed to cast attribute '$key'.", 0, $e);
                }
            }
        }
        return $this;
    }

    protected function transformAttributeOnRead(mixed $value, ?DataType $transformType, ?string $propertyType): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($transformType) {
            return match ($transformType) {
                DataType::INT => (int)$value,
                DataType::BOOL => (bool)$value,
                DataType::FLOAT => (float)$value,
                DataType::STRING => (string)$value,
                DataType::ARRAY, DataType::JSON => is_string($value) ? json_decode($value, true) : $value,
                DataType::DATETIME => is_string($value) ? new DateTime($value) : $value,
                DataType::ENCRYPTED => $value, // Implement decryption here in the future
                default => $value,
            };
        }

        return match ($propertyType) {
            'int' => (int)$value,
            'bool' => (bool)$value,
            'float' => (float)$value,
            'string' => (string)$value,
            'array' => is_string($value) ? json_decode($value, true) : $value,
            'DateTimeImmutable', '\\DateTimeImmutable' => is_string($value) ? new \DateTimeImmutable($value) : $value,
            'DateTime', '\\DateTime', 'DateTimeInterface', '\\DateTimeInterface' => is_string($value) ? new \DateTime($value) : $value,
            default => $value
        };
    }

    protected function transformAttributeOnWrite(mixed $value, DataType $transformType): mixed
    {
        if ($value === null) return null;

        return match ($transformType) {
            DataType::ARRAY, DataType::JSON => json_encode($value),
            DataType::DATETIME => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            DataType::ENCRYPTED => $value, // Implement encryption here in the future
            default => $value,
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

    public function getOriginal(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->_original;
        }
        return $this->_original[$key] ?? null;
    }

    public function isDirty(?string $key = null): bool
    {
        $changes = $this->getChanges();
        if ($key === null) {
            return count($changes) > 0;
        }
        return array_key_exists($key, $changes);
    }

    public function getChanges(): array
    {
        $changes = [];
        $current = $this->_getPublicPropertiesForDb();
        foreach ($current as $key => $value) {
            if (!array_key_exists($key, $this->_original) || $this->_original[$key] !== $value) {
                $changes[$key] = $value;
            }
        }
        return $changes;
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
        $entityAttr = $this->reflection()->getAttributes(\Strux\Component\Database\Schema\Attributes\Entity::class)[0] ?? null;
        if ($entityAttr && $entityAttr->newInstance()->mapper !== null) {
            $mapperClass = $entityAttr->newInstance()->mapper;
            if (class_exists($mapperClass)) {
                $mapper = new $mapperClass();
                if (method_exists($mapper, 'reverseMap')) {
                    return $mapper->reverseMap($this);
                }
            }
        }

        $reflection = new ReflectionClass($this);
        $data = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!empty($prop->getAttributes(RelationAttribute::class, ReflectionAttribute::IS_INSTANCEOF))) {
                continue;
            }

            if (!$prop->isStatic() && !$prop->isProtected() && $prop->isInitialized($this)) {
                $colAttr = $prop->getAttributes(Column::class);
                if (empty($colAttr)) {
                    continue;
                }
                
                $dbName = $prop->getName();
                $colInstance = $colAttr[0]->newInstance();
                if ($colInstance->name) {
                    $dbName = $colInstance->name;
                }
                $value = $this->{$prop->getName()};
                
                $transformAttr = $prop->getAttributes(Transform::class);
                $reformatAttr = $prop->getAttributes(Reformat::class);

                if (!empty($reformatAttr) && $reformatAttr[0]->newInstance()->set) {
                    $method = $reformatAttr[0]->newInstance()->set;
                    if (method_exists($this, $method)) {
                        $value = $this->{$method}($value);
                    } elseif (is_callable($method)) {
                        // Support for global functions like password_hash
                        if ($method === 'password_hash') {
                            $value = password_hash($value, PASSWORD_BCRYPT);
                        } else {
                            $value = $method($value);
                        }
                    }
                } elseif (!empty($transformAttr)) {
                    $value = $this->transformAttributeOnWrite($value, $transformAttr[0]->newInstance()->type);
                }

                $data[$dbName] = $value;
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
