<?php

declare(strict_types=1);

namespace Strux\Component\Mapper;

use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Strux\Component\Mapper\Attributes\MapFrom;
use Strux\Component\Mapper\Attributes\MapTo;
use Strux\Component\Mapper\Attributes\IgnoreMap;
use Strux\Component\Mapper\Attributes\MapConverter;

class Mapper implements MapperInterface
{
    /**
     * @inheritDoc
     */
    public function map(array $source, object|string $target): object
    {
        $targetObject = is_string($target) ? new $target() : $target;
        
        $reflection = new ReflectionClass($targetObject);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);

        foreach ($properties as $property) {
            // Check for IgnoreMap
            if (!empty($property->getAttributes(IgnoreMap::class))) {
                continue;
            }

            // Check for Relation Attributes
            if (!empty($property->getAttributes(\Strux\Component\Database\ORM\Attributes\RelationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF))) {
                continue;
            }

            // Determine source key from MapFrom attribute or property name
            $sourceKey = $property->getName();
            $mapFromAttrs = $property->getAttributes(MapFrom::class);
            if (!empty($mapFromAttrs)) {
                $sourceKey = $mapFromAttrs[0]->newInstance()->key;
            }

            // If the source data doesn't have the key, skip it
            if (!array_key_exists($sourceKey, $source)) {
                continue;
            }

            $value = $source[$sourceKey];

            // Check for MapConverter
            $mapConverterAttrs = $property->getAttributes(MapConverter::class);
            if (!empty($mapConverterAttrs)) {
                $converterClass = $mapConverterAttrs[0]->newInstance()->converterClass;
                /** @var ConverterInterface $converter */
                $converter = new $converterClass();
                $value = $converter->convert($value);
            } else {
                // Perform basic type casting if a type is specified
                $type = $property->getType();
                if ($type instanceof ReflectionNamedType) {
                    $value = $this->castValue($value, $type->getName(), $type->allowsNull());
                }
            }

            // Set the value (making it accessible if protected)
            $property->setAccessible(true);
            $property->setValue($targetObject, $value);
        }

        return $targetObject;
    }

    /**
     * @inheritDoc
     */
    public function reverseMap(object $source): array
    {
        $result = [];
        $reflection = new ReflectionClass($source);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);

        foreach ($properties as $property) {
            if (!empty($property->getAttributes(IgnoreMap::class))) {
                continue;
            }

            // Check for Relation Attributes
            if (!empty($property->getAttributes(\Strux\Component\Database\ORM\Attributes\RelationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF))) {
                continue;
            }

            // Determine target key from MapTo attribute or property name
            $targetKey = $property->getName();
            $mapToAttrs = $property->getAttributes(MapTo::class);
            if (!empty($mapToAttrs)) {
                $targetKey = $mapToAttrs[0]->newInstance()->key;
            }

            // Get value
            $property->setAccessible(true);
            if (!$property->isInitialized($source)) {
                continue; // Skip uninitialized properties
            }
            $value = $property->getValue($source);

            // Check for MapConverter
            $mapConverterAttrs = $property->getAttributes(MapConverter::class);
            if (!empty($mapConverterAttrs)) {
                $converterClass = $mapConverterAttrs[0]->newInstance()->converterClass;
                /** @var ConverterInterface $converter */
                $converter = new $converterClass();
                $value = $converter->reverse($value);
            } else {
                // Format specific types for array output (e.g., DateTime)
                if ($value instanceof DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                }
            }

            $result[$targetKey] = $value;
        }

        return $result;
    }

    /**
     * Cast the incoming scalar value into the appropriate PHP type
     */
    protected function castValue(mixed $value, string $typeName, bool $allowsNull): mixed
    {
        if ($value === null || $value === '') {
            return $allowsNull ? null : $this->getDefaultEmptyValue($typeName);
        }

        return match ($typeName) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'array' => (array) $value,
            DateTime::class, DateTimeInterface::class => new DateTime((string) $value),
            DateTimeImmutable::class => new DateTimeImmutable((string) $value),
            default => $value,
        };
    }

    protected function getDefaultEmptyValue(string $typeName): mixed
    {
        return match ($typeName) {
            'int' => 0,
            'float' => 0.0,
            'string' => '',
            'bool' => false,
            'array' => [],
            default => null,
        };
    }
}
