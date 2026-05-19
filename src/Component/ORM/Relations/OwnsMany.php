<?php

declare(strict_types=1);

namespace Strux\Component\ORM\Relations;

use InvalidArgumentException;
use Strux\Component\ORM\Model;
use Strux\Support\Collection;

class OwnsMany extends Relation
{
    protected string $foreignKey;
    protected string $localKey;

    public function __construct(Model $related, Model $parent, string $foreignKey, string $localKey)
    {
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        parent::__construct($related, $parent);

        if (!$related->getTable()) {
            throw new InvalidArgumentException('Related model must have a table defined.');
        }
    }

    public function getResults(): Collection
    {
        $localKey = $this->parent->{$this->localKey};

        if (empty($localKey)) {
            return new Collection([]);
        }

        return $this->getQuery()
            ->where($this->foreignKey, $localKey)
            ->get();
    }

    public function addEagerConstraints(array $models): void
    {
        $keys = array_map(fn($model) => $model->{$this->localKey}, $models);
        $this->getQuery()->whereIn($this->foreignKey, array_unique($keys));
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $dictionary[$result->{$this->foreignKey}][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->{$this->localKey};
            $model->setRelation($relation, new Collection($dictionary[$key] ?? []));
        }
        return $models;
    }

    /**
     * Create a new instance of the related model.
     * * @param array $attributes
     * @return Model
     */
    public function create(array $attributes): Model
    {
        $instance = new $this->related($attributes);

        $instance->{$this->foreignKey} = $this->parent->{$this->localKey};

        $instance->save();

        return $instance;
    }

    /**
     * Create multiple instances of the related model.
     * * @param array $records Array of attributes
     * @return array Array of created Models
     */
    public function createMany(array $records): array
    {
        $instances = [];
        foreach ($records as $attributes) {
            $instances[] = $this->create($attributes);
        }
        return $instances;
    }

    /**
     * Save a related model instance.
     * * @param Model $model
     * @return Model
     */
    public function save(Model $model): Model
    {
        $model->{$this->foreignKey} = $this->parent->{$this->localKey};
        $model->save();
        return $model;
    }
}
