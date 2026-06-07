<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Relations;

use Strux\Component\Database\ORM\Model;
use Strux\Support\Collection;

abstract class Relation
{
    protected Model $related;
    protected Model $parent;
    protected Model $query; // The query builder instance for the relation.

    public function __construct(Model $related, Model $parent)
    {
        $this->related = $related;
        $this->parent = $parent;
        // Initialize the query builder when the relation is created.
        $this->query = $related::query();
    }

    abstract public function getResults();

    abstract public function addEagerConstraints(array $models): void;

    abstract public function match(array $models, Collection $results, string $relation): array;

    public function getQuery(): Model
    {
        return $this->query;
    }

    /**
     * Forward method calls to the underlying query builder.
     */
    public function __call(string $name, array $arguments)
    {
        $result = $this->query->$name(...$arguments);

        // Return $this for method chaining on the relation, otherwise return the result.
        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }
}
