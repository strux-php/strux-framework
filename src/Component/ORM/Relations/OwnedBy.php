<?php

declare(strict_types=1);

namespace Strux\Component\ORM\Relations;

use InvalidArgumentException;
use Strux\Component\ORM\Model;
use Strux\Support\Collection;

class OwnedBy extends Relation
{
    protected string $foreignKey;
    protected string $ownerKey;

    public function __construct(Model $related, Model $parent, string $foreignKey, string $ownerKey)
    {
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;

        parent::__construct($related, $parent);

        // Ensure the related model is set up correctly
        if (!$related->getTable()) {
            throw new InvalidArgumentException('Related model must have a table defined.');
        }
    }

    public function getResults(): ?Model
    {
        return $this->getQuery()
            ->where($this->ownerKey, $this->parent->{$this->foreignKey})
            ->first();
    }

    public function addEagerConstraints(array $models): void
    {
        $keys = array_map(fn($model) => $model->{$this->foreignKey}, $models);
        $this->getQuery()->whereIn($this->ownerKey, array_unique($keys));
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $dictionary[$result->{$this->ownerKey}] = $result;
        }

        foreach ($models as $model) {
            $key = $model->{$this->foreignKey};
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }
        return $models;
    }
}
