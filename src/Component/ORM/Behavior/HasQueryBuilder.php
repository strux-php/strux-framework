<?php

declare(strict_types=1);

namespace Strux\Component\ORM\Behavior;

use Closure;
use RuntimeException;
use Strux\Component\Database\Expression;
use Strux\Component\Database\Paginator;
use Strux\Component\Exceptions\DatabaseException;
use Strux\Component\ORM\Model;
use Strux\Support\Bridge\Request;
use Strux\Support\Collection;

trait HasQueryBuilder
{
    use HasRelationships;

    private string $_queryAction = 'SELECT';
    private bool $_distinct = false;
    private array $_selects = [];
    private ?string $_from = null;
    private array $_joins = [];
    private array $_wheres = [];
    private array $_groups = [];
    private array $_havings = [];
    private array $_orders = [];
    private ?int $_limit = null;
    private ?int $_offset = null;
    private array $_compiledBindings = [];
    private bool $_isQueryBuilderInstance = false;

    public static function query(): static
    {
        /** @var Model $instance */
        $instance = new static();
        $instance->_resetQueryState();
        $instance->_isQueryBuilderInstance = true;

        if (method_exists($instance, 'applyGlobalScopes')) {
            $instance->applyGlobalScopes($instance);
        }

        return $instance;
    }

    private function _resetQueryState(): void
    {
        $this->_queryAction = 'SELECT';
        $this->_distinct = false;
        $this->_selects = [];
        $this->_from = null;
        $this->_joins = [];
        $this->_wheres = [];
        $this->_groups = [];
        $this->_havings = [];
        $this->_orders = [];
        $this->_limit = null;
        $this->_offset = null;
        $this->_compiledBindings = [];
        $this->_with = [];
    }

    private function _getQueryBuilderInstance(): static
    {
        return $this->_isQueryBuilderInstance ? $this : static::query();
    }

    public static function raw(string $expression): Expression
    {
        return new Expression($expression);
    }

    protected function select(array|string $columns = ['*']): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_queryAction = 'SELECT';
        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $column) {
            $builder->_selects[] = ['sql' => $column, 'bindings' => []];
        }
        return $builder;
    }

    protected function selectRaw(string $expression, array $bindings = []): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_selects[] = ['sql' => new Expression($expression), 'bindings' => $bindings];
        return $builder;
    }

    /**
     * Set the table which the query is targeting.
     */
    protected function from(string $table): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_from = $table;
        return $builder;
    }

    protected function distinct(): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_distinct = true;
        return $builder;
    }

    // --- Where Clauses ---

    protected function where(array|string|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        $builder = $this->_getQueryBuilderInstance();

        if ($column instanceof Closure) {
            $nestedBuilder = static::query();
            $column($nestedBuilder);

            $builder->_wheres[] = [
                'type' => 'nested',
                'query' => $nestedBuilder,
                'boolean' => $boolean
            ];
            return $builder;
        }

        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $builder->where($key, '=', $val, $boolean);
            }
            return $builder;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if ($value instanceof Closure) {
            return $builder->whereSub($column, $operator, $value, $boolean);
        }

        $operator = strtoupper((string) $operator);
        $needsBinding = !in_array($operator, ['IS NULL', 'IS NOT NULL']);

        $builder->_wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
            'bindings' => $needsBinding ? [$value] : []
        ];

        return $builder;
    }

    /**
     * Handle a where clause comparing a column to a subquery.
     */
    protected function whereSub(string $column, string $operator, Closure $callback, string $boolean): static
    {
        $subBuilder = static::query();
        $callback($subBuilder);

        $sql = $subBuilder->_buildSelectSQL();
        $bindings = $subBuilder->_compiledBindings;

        return $this->whereRaw("{$column} {$operator} ({$sql})", $bindings, $boolean);
    }

    protected function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    protected function whereNot(Closure $callback): static
    {
        return $this->where($callback, null, null, 'AND NOT');
    }

    protected function orWhereNot(Closure $callback): static
    {
        return $this->where($callback, null, null, 'OR NOT');
    }

    protected function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => $boolean,
            'bindings' => $bindings
        ];
        return $builder;
    }

    protected function orWhereRaw(string $sql, array $bindings = []): static
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    protected function whereIn(string $column, array|Closure $values, string $boolean = 'AND', bool $not = false): static
    {
        $builder = $this->_getQueryBuilderInstance();

        if ($values instanceof Closure) {
            $operator = $not ? 'NOT IN' : 'IN';
            return $builder->whereSub($column, $operator, $values, $boolean);
        }

        if (empty($values)) {
            return $builder->whereRaw('1 = 0', [], $boolean);
        }

        $builder->_wheres[] = [
            'type' => $not ? 'not_in' : 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
            'bindings' => $values
        ];
        return $builder;
    }

    protected function whereNotIn(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    // --- Advanced Where Helpers ---

    protected function whereAny(array $columns, string $operator, mixed $value): static
    {
        return $this->where(function ($query) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $query->orWhere($column, $operator, $value);
            }
        });
    }

    protected function whereAll(array $columns, string $operator, mixed $value): static
    {
        return $this->where(function ($query) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value);
            }
        });
    }

    protected function whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND', bool $not = false): static
    {
        $operator = $not ? 'NOT LIKE' : 'LIKE';

        if ($caseSensitive) {
            return $this->whereRaw("BINARY `$column` $operator ?", [$value], $boolean);
        }

        return $this->where($column, $operator, $value, $boolean);
    }

    protected function orWhereLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, 'OR');
    }

    protected function whereNotLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, 'AND', true);
    }

    protected function orWhereNotLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, 'OR', true);
    }

    // --- Joins ---

    protected function _addJoin(string $type, string $table, string $first, ?string $operatorOrSecond = null, ?string $second = null): static
    {
        $second = $second ?? $operatorOrSecond;
        $operator = $second === $operatorOrSecond ? '=' : $operatorOrSecond;

        $this->_joins[] = compact('type', 'table', 'first', 'operator', 'second');
        return $this;
    }

    protected function join(string $table, string $first, mixed $operatorOrSecond, mixed $second = null): static
    {
        $builder = $this->_getQueryBuilderInstance();
        return $builder->_addJoin('INNER', $table, $first, $operatorOrSecond, $second);
    }

    protected function leftJoin(string $table, string $first, ?string $operatorOrSecond = null, ?string $second = null): static
    {
        $builder = $this->_getQueryBuilderInstance();
        return $builder->_addJoin('LEFT', $table, $first, $operatorOrSecond, $second);
    }

    protected function rightJoin(string $table, string $first, ?string $operatorOrSecond = null, ?string $second = null): static
    {
        $builder = $this->_getQueryBuilderInstance();
        return $builder->_addJoin('RIGHT', $table, $first, $operatorOrSecond, $second);
    }

    // --- Ordering & Grouping ---

    protected function orderBy(string $column, string $direction = 'ASC'): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_orders[] = compact('column', 'direction');
        return $builder;
    }

    protected function groupBy(string ...$columns): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_groups = array_merge($builder->_groups, $columns);
        return $builder;
    }

    protected function having(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_havings[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
            'bindings' => [$value]
        ];
        return $builder;
    }

    protected function limit(int $limit): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_limit = $limit;
        return $builder;
    }

    protected function offset(int $offset): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_offset = $offset;
        return $builder;
    }

    // --- Retrieval ---

    protected function exists(): bool
    {
        $checkBuilder = static::query();
        $this->_copyQueryState($this->_getQueryBuilderInstance(), $checkBuilder);

        $checkBuilder->selectRaw('1')->limit(1);

        $sql = $checkBuilder->_buildSelectSQL();

        try {
            $stmt = $checkBuilder->_execute($sql, $checkBuilder->_compiledBindings);
        } catch (DatabaseException $e) {
            return false;
        }

        return (bool) $stmt->fetchColumn();
    }

    protected function get(): Collection
    {
        $builder = $this->_getQueryBuilderInstance();
        $sql = $builder->_buildSelectSQL();

        try {
            $stmt = $builder->_execute($sql, $builder->_compiledBindings);
        } catch (DatabaseException $e) {
            throw new RuntimeException("Query Error: " . $e->getMessage() . " [SQL: $sql]");
        }

        $results = $stmt->fetchAll();
        $models = array_map(fn($row) => static::fromStorage($row), $results ?: []);

        if (!empty($models) && !empty($builder->_with)) {
            $this->eagerLoadRelations($models, $builder->_with);
        }

        return new Collection($models);
    }

    protected function all(): Collection
    {
        return $this->get();
    }

    protected function first(): ?static
    {
        $this->limit(1);
        $collection = $this->get();
        return $collection->first();
    }

    protected function last(): ?static
    {
        $this->orderBy($this->getPrimaryKey(), 'DESC')->limit(1);
        return $this->get()->first();
    }

    public static function find(mixed $id, array $with = []): ?static
    {
        $instance = new static();
        $query = static::query()->where($instance->getPrimaryKey(), $id);
        if ($with)
            $query->with(...$with);
        return $query->first();
    }

    public static function findOrFail(mixed $id, array $with = []): static
    {
        $result = static::find($id, $with);
        if (!$result) {
            throw new RuntimeException("Model not found with ID: " . (is_array($id) ? json_encode($id) : $id));
        }
        return $result;
    }

    protected function latest(?string $column = null): static
    {
        $column = $column ?? $this->getCreatedAtColumn();
        if (!$column)
            throw new RuntimeException("Cannot use latest() on a model without timestamps enabled.");
        return $this->orderBy($column, 'DESC');
    }

    protected function oldest(?string $column = null): static
    {
        $column = $column ?? $this->getCreatedAtColumn();
        if (!$column)
            throw new RuntimeException("Cannot use oldest() on a model without timestamps enabled.");
        return $this->orderBy($column, 'ASC');
    }

    // --- Bulk Operations ---

    protected function insert(array $values): bool
    {
        if (empty($values))
            return true;

        if (!is_array(reset($values))) {
            $values = [$values];
        }

        $builder = $this->_getQueryBuilderInstance();
        $table = $builder->_from ?? $builder->getTable();

        $columns = array_keys(reset($values));
        $bindings = [];
        $placeholders = [];

        foreach ($values as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $column) {
                $rowPlaceholders[] = '?';
                $bindings[] = $row[$column] ?? null;
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $columnsStr = '`' . implode('`, `', $columns) . '`';
        $placeholdersStr = implode(', ', $placeholders);

        $sql = "INSERT INTO `{$table}` ({$columnsStr}) VALUES {$placeholdersStr}";

        try {
            $builder->_execute($sql, $bindings);
            return true;
        } catch (DatabaseException $e) {
            return false;
        }
    }

    protected function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        if (empty($values))
            return 0;

        if (!is_array(reset($values))) {
            $values = [$values];
        }

        $builder = $this->_getQueryBuilderInstance();
        $table = $builder->_from ?? $builder->getTable();

        $columns = array_keys(reset($values));
        $bindings = [];
        $placeholders = [];

        foreach ($values as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $column) {
                $rowPlaceholders[] = '?';
                $bindings[] = $row[$column] ?? null;
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $columnsStr = '`' . implode('`, `', $columns) . '`';
        $placeholdersStr = implode(', ', $placeholders);

        $sql = "INSERT INTO `{$table}` ({$columnsStr}) VALUES {$placeholdersStr}";

        if (empty($update)) {
            $update = array_filter($columns, fn($col) => !in_array($col, (array) $uniqueBy));
        }

        if (!empty($update)) {
            $updateClauses = [];
            foreach ($update as $key => $value) {
                if (is_int($key)) {
                    $updateClauses[] = "`{$value}` = VALUES(`{$value}`)";
                } else {
                    $updateClauses[] = "`{$key}` = ?";
                    throw new RuntimeException("Custom upsert binding values not currently supported. Pass column names as values.");
                }
            }
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateClauses);
        }

        try {
            $stmt = $builder->_execute($sql, $bindings);
            return $stmt->rowCount();
        } catch (DatabaseException $e) {
            return 0;
        }
    }

    // --- Aggregates ---

    protected function _aggregate(string $function, string $column): mixed
    {
        $builder = $this->_getQueryBuilderInstance();
        $aggregateBuilder = static::query();

        $this->_copyQueryState($builder, $aggregateBuilder);

        $aggregateBuilder->_orders = [];
        $aggregateBuilder->_limit = null;
        $aggregateBuilder->_offset = null;

        $aggregateBuilder->selectRaw("{$function}({$column}) as aggregate");

        $sql = $aggregateBuilder->_buildSelectSQL();
        $stmt = $aggregateBuilder->_execute($sql, $aggregateBuilder->_compiledBindings);

        return $stmt->fetchColumn();
    }

    protected function count(string $column = '*'): int
    {
        return (int) $this->_aggregate('COUNT', $column);
    }

    protected function max(string $column): mixed
    {
        return $this->_aggregate('MAX', $column);
    }

    protected function min(string $column): mixed
    {
        return $this->_aggregate('MIN', $column);
    }

    protected function avg(string $column): mixed
    {
        return $this->_aggregate('AVG', $column);
    }

    protected function sum(string $column): mixed
    {
        return $this->_aggregate('SUM', $column);
    }

    // --- SQL Building & Compilation ---

    private function _buildSelectSQL(): string
    {
        if (!$this->_isQueryBuilderInstance || $this->_queryAction !== 'SELECT') {
            throw new RuntimeException("Invalid query action.");
        }

        $this->_compiledBindings = [];

        $table = $this->_from ?? $this->getTable();

        if (empty($this->_selects)) {
            $selectStr = "`$table`.*";
        } else {
            $selectParts = [];
            foreach ($this->_selects as $select) {
                $selectParts[] = (string) $select['sql'];
                if (!empty($select['bindings'])) {
                    $this->_compiledBindings = array_merge($this->_compiledBindings, $select['bindings']);
                }
            }
            $selectStr = implode(', ', $selectParts);
        }

        $distinct = $this->_distinct ? 'DISTINCT ' : '';
        $sql = "SELECT $distinct$selectStr FROM `$table`";

        foreach ($this->_joins as $join) {
            $sql .= " {$join['type']} JOIN `{$join['table']}` ON {$join['first']} {$join['operator']} {$join['second']}";
        }

        if (!empty($this->_wheres)) {
            $sql .= " WHERE " . $this->_compileWheres($this->_wheres);
        }

        if (!empty($this->_groups)) {
            $sql .= " GROUP BY " . implode(', ', $this->_groups);
        }

        if (!empty($this->_havings)) {
            $sql .= " HAVING ";
            foreach ($this->_havings as $i => $having) {
                if ($i > 0)
                    $sql .= " {$having['boolean']} ";
                $sql .= "{$having['column']} {$having['operator']} ?";
                $this->_compiledBindings = array_merge($this->_compiledBindings, $having['bindings']);
            }
        }

        if (!empty($this->_orders)) {
            $sql .= " ORDER BY " . implode(', ', array_map(fn($o) => "{$o['column']} {$o['direction']}", $this->_orders));
        }

        if ($this->_limit !== null)
            $sql .= " LIMIT $this->_limit";
        if ($this->_offset !== null)
            $sql .= " OFFSET $this->_offset";

        return $sql;
    }

    private function _compileWheres(array $wheres): string
    {
        $sql = '';
        foreach ($wheres as $i => $where) {
            if ($i > 0) {
                $boolean = strtoupper($where['boolean']);
                $sql .= " $boolean ";
            } elseif ($where['boolean'] === 'AND NOT' || $where['boolean'] === 'OR NOT') {
                $sql .= 'NOT ';
            }

            if ($where['type'] === 'nested') {
                $nestedQuery = $where['query'];
                $nestedSql = $this->_compileWheres($nestedQuery->_wheres);
                if ($nestedSql) {
                    $sql .= "($nestedSql)";
                }
            } elseif ($where['type'] === 'raw') {
                $sql .= $where['sql'];
                if (!empty($where['bindings'])) {
                    $this->_compiledBindings = array_merge($this->_compiledBindings, $where['bindings']);
                }
            } elseif ($where['type'] === 'in' || $where['type'] === 'not_in') {
                $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                $operator = ($where['type'] === 'in') ? 'IN' : 'NOT IN';
                $sql .= "{$where['column']} {$operator} ({$placeholders})";
                $this->_compiledBindings = array_merge($this->_compiledBindings, $where['bindings']);
            } elseif ($where['type'] === 'basic') {
                $sql .= "{$where['column']} {$where['operator']} ?";
                $this->_compiledBindings = array_merge($this->_compiledBindings, $where['bindings']);
            }
        }
        return $sql;
    }

    // --- Pagination ---

    protected function paginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
        ?string $path = '',
        array $query = []
    ): Paginator {
        $page = $page ?: Request::query($pageName, 1, type: 'int');
        $query = $query ?: Request::allQuery();
        if ($page < 1)
            $page = 1;

        $countBuilder = static::query();
        $this->_copyQueryState($this, $countBuilder);
        $total = $countBuilder->count();

        $itemBuilder = static::query();
        $this->_copyQueryState($this, $itemBuilder);

        $itemBuilder->limit($perPage);
        $itemBuilder->offset(($page - 1) * $perPage);
        if ($columns !== ['*'])
            $itemBuilder->select($columns);

        $results = $itemBuilder->get();

        return new Paginator($results, $total, $perPage, $page, $path, (array) $query);
    }

    private function _copyQueryState($from, $to): void
    {
        $to->_wheres = $from->_wheres;
        $to->_joins = $from->_joins;
        $to->_groups = $from->_groups;
        $to->_havings = $from->_havings;
        $to->_distinct = $from->_distinct;
        $to->_orders = $from->_orders;
        $to->_selects = $from->_selects;
        $to->_from = $from->_from;
        $to->_with = $from->_with;
    }

    // --- Debugging ---

    protected function toSql(): string
    {
        return $this->_getQueryBuilderInstance()->_buildSelectSQL();
    }

    protected function toRawSql(): string
    {
        $sql = $this->toSql();
        $bindings = $this->_compiledBindings;

        foreach ($bindings as $bind) {
            if (is_string($bind))
                $bind = "'" . addslashes($bind) . "'";
            elseif (is_null($bind))
                $bind = 'NULL';
            elseif (is_bool($bind))
                $bind = $bind ? '1' : '0';
            elseif ($bind instanceof \DateTimeInterface)
                $bind = "'" . $bind->format('Y-m-d H:i:s') . "'";

            $pos = strpos($sql, '?');
            if ($pos !== false) {
                $sql = substr_replace($sql, (string) $bind, $pos, 1);
            }
        }
        return $sql;
    }
}
