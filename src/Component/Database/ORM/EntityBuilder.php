<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM;

use Strux\Support\Collection;

abstract class EntityBuilder
{
    /**
     * The model class being built.
     */
    protected string $model;

    /**
     * The number of models to build.
     */
    protected ?int $count = null;

    /**
     * Create a new builder instance.
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Set the number of models to build.
     */
    public function count(int $count): static
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Define the model's default state.
     */
    abstract public function definition(): array;

    /**
     * Create instances of the model without saving to the database.
     *
     * @return Model|Collection
     */
    public function make(array $attributes = []): Model|Collection
    {
        if ($this->count === null) {
            return $this->makeInstance($attributes);
        }

        $collection = new Collection();
        for ($i = 0; $i < $this->count; $i++) {
            $collection->push($this->makeInstance($attributes));
        }

        return $collection;
    }

    /**
     * Create and save instances of the model.
     *
     * @return Model|Collection
     */
    public function create(array $attributes = []): Model|Collection
    {
        $results = $this->make($attributes);

        if ($results instanceof Collection) {
            foreach ($results as $model) {
                $model->save();
            }
        } else {
            $results->save();
        }

        return $results;
    }

    /**
     * Make a single model instance.
     */
    protected function makeInstance(array $attributes = []): Model
    {
        $modelClass = $this->model;
        /** @var Model $instance */
        $instance = new $modelClass();
        
        $definition = $this->definition();
        $merged = array_merge($definition, $attributes);

        foreach ($merged as $key => $value) {
            $instance->{$key} = $value;
        }

        return $instance;
    }
}
