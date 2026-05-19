<?php

declare(strict_types=1);

namespace Strux\Component\ORM\Relations;

use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Strux\Component\Exceptions\Container\ContainerException;
use Strux\Component\ORM\Model;
use Strux\Support\Collection;
use Strux\Support\ContainerBridge;

class OwnedByMany extends Relation
{
    private string $pivotTable;
    private string $foreignPivotKey;
    private string $relatedPivotKey;
    private string $parentKey;
    private string $relatedKey;

    public function __construct(
        Model  $related,
        Model  $parent,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey
    )
    {
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;

        parent::__construct($related, $parent);

        if (!$related->getTable()) {
            throw new InvalidArgumentException('Related model must have a table defined.');
        }
    }

    public function getResults(): Collection
    {
        return $this->getQuery()
            ->select($this->related->getTable() . '.*')
            ->join($this->pivotTable, $this->related->getTable() . '.' . $this->relatedKey, '=', $this->pivotTable . '.' . $this->relatedPivotKey)
            ->where($this->pivotTable . '.' . $this->foreignPivotKey, '=', $this->parent->{$this->parentKey})
            ->get();
    }

    public function addEagerConstraints(array $models): void
    {
        $keys = array_map(fn($model) => $model->{$this->parentKey}, $models);
        $this->getQuery()
            ->select($this->related->getTable() . '.*', $this->pivotTable . '.' . $this->foreignPivotKey . ' as pivot_key')
            ->join($this->pivotTable, $this->related->getTable() . '.' . $this->relatedKey, '=', $this->pivotTable . '.' . $this->relatedPivotKey)
            ->whereIn($this->pivotTable . '.' . $this->foreignPivotKey, array_unique($keys));
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $pivotKeyValue = $result->pivot_key;
            $dictionary[$pivotKeyValue][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->{$this->parentKey};
            $model->setRelation($relation, new Collection($dictionary[$key] ?? []));
        }
        return $models;
    }

    /**
     * Attach a related model to the parent.
     * * @param array|int|Model $id
     * @param array $attributes Additional pivot attributes
     */
    public function attach(array|Model|int $id, array $attributes = []): void
    {
        $ids = is_array($id) ? $id : [$id];
        $parentKey = $this->parent->{$this->parent->getPrimaryKey()};

        try {
            $db = ContainerBridge::resolve(\PDO::class);
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $db = container()->get(\PDO::class);
        }

        $columns = [$this->foreignPivotKey, $this->relatedPivotKey];
        $placeholders = ['?', '?'];

        foreach ($attributes as $key => $val) {
            $columns[] = $key;
            $placeholders[] = '?';
        }

        $sql = "INSERT INTO {$this->pivotTable} (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $db->prepare($sql);

        foreach ($ids as $relatedId) {
            if ($relatedId instanceof Model) {
                $relatedId = $relatedId->{$relatedId->getPrimaryKey()};
            }

            $params = array_merge([$parentKey, $relatedId], array_values($attributes));

            try {
                $stmt->execute($params);
            } catch (\PDOException $e) {
                if ($e->getCode() != 23000) throw $e;
            }
        }
    }

    /**
     * Detach related models.
     * * @param int|array|Model|null $ids If null, detach all.
     */
    public function detach(int|Model|array $ids = null): void
    {
        $parentKey = $this->parent->{$this->parent->getPrimaryKey()};

        try {
            $db = ContainerBridge::resolve(\PDO::class);
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $db = container()->get(\PDO::class);
        }

        $sql = "DELETE FROM {$this->pivotTable} WHERE {$this->foreignPivotKey} = ?";
        $params = [$parentKey];

        if (!is_null($ids)) {
            $ids = is_array($ids) ? $ids : [$ids];

            $ids = array_map(function ($id) {
                return ($id instanceof Model) ? $id->{$id->getPrimaryKey()} : $id;
            }, $ids);

            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql .= " AND {$this->relatedPivotKey} IN ($placeholders)";
                $params = array_merge($params, $ids);
            }
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Sync the intermediate table with a list of IDs.
     * * @param array $ids
     * @param bool $detaching
     */
    public function sync(array $ids, bool $detaching = true): void
    {
        $currentResults = $this->getResults();
        $currentIds = [];

        foreach ($currentResults as $model) {
            $currentIds[] = $model->{$model->getPrimaryKey()};
        }

        if ($detaching) {
            $detach = array_diff($currentIds, $ids);
            if (count($detach) > 0) {
                $this->detach($detach);
            }
        }

        $attach = array_diff($ids, $currentIds);
        if (count($attach) > 0) {
            $this->attach($attach);
        }
    }

    public function add($item): static
    {
        $this->attach($item);
        return $this;
    }

    public function remove($item): static
    {
        $this->detach($item);
        return $this;
    }
}
