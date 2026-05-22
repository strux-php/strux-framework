<?php

declare(strict_types=1);

namespace Strux\Component\ORM\Behavior;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Strux\Component\Database\Attributes\Table;
use Strux\Component\ORM\Attributes\OwnedBy as OwnedByAttr;
use Strux\Component\ORM\Attributes\OwnedByMany as OwnedByManyAttr;
use Strux\Component\ORM\Attributes\OwnsMany as OwnsManyAttr;
use Strux\Component\ORM\Attributes\OwnsOne as OwnsOneAttr;
use Strux\Component\ORM\Attributes\RelationAttribute;
use Strux\Component\ORM\Model;
use Strux\Component\ORM\Relations\OwnedBy;
use Strux\Component\ORM\Relations\OwnedByMany;
use Strux\Component\ORM\Relations\OwnsMany;
use Strux\Component\ORM\Relations\OwnsOne;
use Strux\Component\ORM\Relations\Relation;
use Strux\Support\Collection;
use Strux\Support\Helpers\Utils;

use function array_merge;

trait HasRelationships
{
    private array $_relations = [];
    private array $_with = [];

    /**
     * Magic method to handle dynamic relationship method calls.
     * Allows $model->someMethod() to return the Relation object if the property $someMethod exists with a RelationAttribute.
     * @throws ReflectionException
     */
    public function __call(string $method, array $arguments)
    {
        if (method_exists($this, $method)) {
            return $this->{$method}(...$arguments);
        }

        if (property_exists($this, $method)) {
            $reflection = new ReflectionClass($this);
            $property = $reflection->getProperty($method);

            $attributes = $property->getAttributes(RelationAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

            if (!empty($attributes)) {
                return $this->initializeRelationFromAttribute($attributes[0]);
            }
        }

        $scopeMethod = 'scope' . ucfirst($method);
        if (method_exists($this, $scopeMethod)) {
            $builder = $this->_getQueryBuilderInstance();
            $result = $this->{$scopeMethod}($builder, ...$arguments);
            return $result ?? $builder;
        }

        throw new RuntimeException("Method '$method' does not exist on " . static::class);
    }

    protected function with(mixed ...$relations): static
    {
        $builder = $this->_getQueryBuilderInstance();
        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $builder->_with[$relation] = null;
            } elseif (is_array($relation)) {
                foreach ($relation as $key => $value) {
                    if (is_int($key)) {
                        $builder->_with[$value] = null;
                    } else {
                        $builder->_with[$key] = $value;
                    }
                }
            }
        }
        return $builder;
    }

    protected function eagerLoadRelations(array $models, array $relations): void
    {
        // Handle case where relations array is list of strings vs assoc array
        $normalizedRelations = [];
        foreach ($relations as $key => $value) {
            if (is_int($key)) {
                $normalizedRelations[$value] = null;
            } else {
                $normalizedRelations[$key] = $value;
            }
        }

        $nestedRelations = $this->parseNestedRelations(array_keys($normalizedRelations));

        foreach ($nestedRelations as $relationName => $nestedChildren) {
            if (!property_exists($models[0], $relationName)) {
                throw new RuntimeException("Relationship property '$relationName' not found on model " . get_class($models[0]));
            }

            $prop = new ReflectionProperty($models[0], $relationName);
            $attributes = $prop->getAttributes(RelationAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

            if (empty($attributes)) {
                throw new RuntimeException("Property '$relationName' is not a valid relationship.");
            }

            $relationInstance = $this->initializeRelationFromAttribute($attributes[0]);
            $relationInstance->addEagerConstraints($models);

            if (isset($normalizedRelations[$relationName]) && is_callable($normalizedRelations[$relationName])) {
                $normalizedRelations[$relationName]($relationInstance->getQuery());
            }

            $relatedModels = $relationInstance->getQuery()->get();

            if (!empty($nestedChildren) && !$relatedModels->isEmpty()) {
                $nextLevelRelations = $this->buildRelationsForNextLevel($nestedChildren);
                $relatedModels->first()->eagerLoadRelations($relatedModels->all(), $nextLevelRelations);
            }

            $this->matchEagerlyLoadedRelations($relationInstance, $models, $relatedModels, $relationName);
        }
    }

    private function initializeRelationFromAttribute(ReflectionAttribute $attribute): Relation
    {
        $attrInstance = $attribute->newInstance();
        return match ($attribute->getName()) {
            OwnsOneAttr::class => $this->ownsOne($attrInstance->related, $attrInstance->foreignKey, $attrInstance->localKey),
            OwnsManyAttr::class => $this->ownsMany($attrInstance->related, $attrInstance->foreignKey, $attrInstance->localKey),
            OwnedByAttr::class => $this->ownedBy($attrInstance->related, $attrInstance->foreignKey, $attrInstance->ownerKey),
            OwnedByManyAttr::class => $this->ownedByMany($attrInstance->related, $attrInstance->pivotTable, $attrInstance->foreignPivotKey, $attrInstance->relatedPivotKey),
            default => throw new RuntimeException("Unknown relationship attribute: " . $attribute->getName()),
        };
    }

    private function parseNestedRelations(array $relations): array
    {
        $parsed = [];
        foreach ($relations as $name) {
            $keys = explode('.', (string)$name);
            $temp = &$parsed;
            foreach ($keys as $key) {
                if (!isset($temp[$key]))
                    $temp[$key] = [];
                $temp = &$temp[$key];
            }
        }
        return $parsed;
    }

    private function buildRelationsForNextLevel(array $nestedTree): array
    {
        $relations = [];
        $buildPath = function (array $tree, string $prefix = '') use (&$buildPath, &$relations) {
            foreach ($tree as $name => $nested) {
                $currentPath = $prefix ? "{$prefix}.{$name}" : $name;
                if (empty($nested))
                    $relations[] = $currentPath;
                else
                    $buildPath($nested, $currentPath);
            }
        };
        $buildPath($nestedTree);
        return $relations;
    }

    protected function matchEagerlyLoadedRelations(Relation $relation, array &$parents, Collection $results, string $relationName): void
    {
        $relation->match($parents, $results, $relationName);
    }

    public function setRelation(string $relation, mixed $value): void
    {
        $this->_relations[$relation] = $value;
        try {
            $reflection = new ReflectionClass($this);
            if ($reflection->hasProperty($relation)) {
                $property = $reflection->getProperty($relation);
                if ($property->isPublic()) {
                    $property->setValue($this, $value);
                }
            }
        } catch (ReflectionException $e) {
        }
    }

    // --- Definition Methods ---

    protected function ownsOne(string $relatedModel, ?string $foreignKey = null, ?string $localKey = null): OwnsOne
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new $relatedModel();
        $foreignKey = $foreignKey ?: strtolower((new ReflectionClass($this))->getShortName()) . '_' . $this->getPrimaryKey();
        $localKey = $localKey ?: $this->getPrimaryKey();

        return new OwnsOne($relatedInstance, $this, $foreignKey, $localKey);
    }

    protected function ownsMany(string $relatedModel, ?string $foreignKey = null, ?string $localKey = null): OwnsMany
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new $relatedModel();
        $foreignKey = $foreignKey ?: strtolower((new ReflectionClass($this))->getShortName()) . '_' . $this->getPrimaryKey();
        $localKey = $localKey ?: $this->getPrimaryKey();
        return new OwnsMany($relatedInstance, $this, $foreignKey, $localKey);
    }

    protected function ownedBy(string $relatedModel, ?string $foreignKey = null, ?string $ownerKey = null): OwnedBy
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new $relatedModel();
        $foreignKey = $foreignKey ?: strtolower($relatedInstance->reflection()->getShortName()) . '_' . $relatedInstance->getPrimaryKey();
        $ownerKey = $ownerKey ?: $relatedInstance->getPrimaryKey();
        return new OwnedBy($relatedInstance, $this, $foreignKey, $ownerKey);
    }

    protected function ownedByMany(string $relatedModel, ?string $pivotTable = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): OwnedByMany
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new $relatedModel();

        if ($pivotTable && class_exists($pivotTable)) {
            $pivotReflection = new ReflectionClass($pivotTable);
            $tableAttr = $pivotReflection->getAttributes(Table::class)[0] ?? null;
            if ($tableAttr) {
                $pivotTable = $tableAttr->newInstance()->name;
            }
        }

        if ($pivotTable === null) {
            $models = [
                $this->getTable() ?? Utils::getPluralName($this->reflection()->getShortName()),
                $relatedInstance->getTable() ?? Utils::getPluralName($relatedInstance->reflection()->getShortName())
            ];
            sort($models);
            $pivotTable = implode('_', $models);
        }

        $foreignPivotKey = $foreignPivotKey ?? $this->getPrimaryKey() ?? strtolower($this->reflection()->getShortName()) . '_' . $this->getPrimaryKey();
        $relatedPivotKey = $relatedPivotKey ?? $relatedInstance->getPrimaryKey() ?? strtolower($relatedInstance->reflection()->getShortName()) . '_' . $relatedInstance->getPrimaryKey();

        return new OwnedByMany($relatedInstance, $this, $pivotTable, $foreignPivotKey, $relatedPivotKey, $this->getPrimaryKey(), $relatedInstance->getPrimaryKey());
    }
}
