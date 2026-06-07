<?php

namespace Strux\Component\Database\ORM\Relations;

use InvalidArgumentException;
use Strux\Component\Database\ORM\Model;
use Strux\Support\Collection;

class OwnsOne extends Relation
{
    protected string $foreignKey;
    protected string $localKey;

    public function __construct(Model $related, Model $parent, string $foreignKey, string $localKey)
    {
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        parent::__construct($related, $parent);

        // Ensure the related model is set up correctly
        if (!$related->getTable()) {
            throw new InvalidArgumentException('Related model must have a table defined.');
        }

        $this->addBaseConstraints();
    }

    public function addBaseConstraints(): void
    {
        $localKey = $this->parent->{$this->localKey};
        if (!empty($localKey)) {
            $this->getQuery()->where($this->foreignKey, $localKey);
        }
    }

    /**
     * Get the result for a lazy-loaded relationship.
     */
    public function getResults(): ?Model
    {
        if (empty($this->parent->{$this->localKey})) {
            return null;
        }

        return $this->getQuery()->first();
    }

    /**
     * Add constraints for an eager-loaded relationship.
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = array_map(fn($model) => $model->{$this->localKey}, $models);
        $this->getQuery()->whereIn($this->foreignKey, array_unique($keys));
    }

    /**
     * Match the eager-loaded results back to their parents.
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $dictionary[$result->{$this->foreignKey}] = $result;
        }

        foreach ($models as $model) {
            $key = $model->{$this->localKey};
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }
        return $models;
    }
}
