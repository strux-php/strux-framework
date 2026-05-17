<?php

declare(strict_types=1);

namespace Strux\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class Collection implements IteratorAggregate, Countable, JsonSerializable
{
    protected array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function add(mixed $item): self
    {
        $this->items[] = $item;
        return $this;
    }

    public function remove(mixed $item): self
    {
        $key = array_search($item, $this->items, true);
        if ($key !== false) {
            unset($this->items[$key]);
            $this->items = array_values($this->items);
        }
        return $this;
    }

    public function contains(mixed $item): bool
    {
        return in_array($item, $this->items, true);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function last(): mixed
    {
        $last = end($this->items);
        reset($this->items);
        return $last ?: null;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function map(callable $callback): self
    {
        return new static(array_map($callback, $this->items));
    }

    public function filter(callable $callback): self
    {
        return new static(array_values(array_filter($this->items, $callback)));
    }

    public function hide(array $fields, ?callable $condition = null): static
    {
        foreach ($this->items as $item) {
            if (method_exists($item, 'hide')) {
                $item->hide($fields, $condition);
            }
        }
        return $this;
    }

    public function unhide(array $fields, ?callable $condition = null): static
    {
        foreach ($this->items as $item) {
            if (method_exists($item, 'unhide')) {
                $item->unhide($fields, $condition);
            }
        }
        return $this;
    }

    public function toArray(): array
    {
        return array_map(fn($item) => is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : $item, $this->items);
    }
}