<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Behavior;

use Closure;
use RuntimeException;
use Strux\Component\Database\Expression;
use Strux\Component\Database\Paginator;
use Strux\Component\Exceptions\DatabaseException;
use Strux\Component\Database\ORM\Model;
use Strux\Support\Bridge\Request;
use Strux\Support\Collection;
use Strux\Component\Database\ORM\Dialect\SqlDialect;
use Strux\Component\Database\ORM\Dialect\MySqlDialect;
use Strux\Component\Database\ORM\Dialect\PostgresDialect;
use Strux\Component\Database\ORM\Dialect\SqliteDialect;
use Strux\Component\Database\ORM\Dialect\SqlServerDialect;
use Strux\Support\ContainerBridge;
use Strux\Component\Cache\Cache;
use Strux\Component\Database\ORM\Attributes\Stash;

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
    private ?int $_take = null;
    private ?int $_skip = null;
    private array $_compiledBindings = [];
    private bool $_isQueryBuilderInstance = false;
    private ?SqlDialect $_dialect = null;
    private ?int $_stashFor = null;
    private ?string $_stashKey = null;

    public function getDialect(): SqlDialect
    {
        if ($this->_dialect === null) {
            $pdo = \Strux\Support\ContainerBridge::resolve(\PDO::class);
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            $this->_dialect = match($driver) {
                'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                default => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
            };
        }
        return $this->_dialect;
    }

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
        $this->_take = null;
        $this->_skip = null;
        $this->_compiledBindings = [];
        $this->_with = [];
        $this->_stashFor = null;
        $this->_stashKey = null;
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

    protected function take(int $take): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_take = $take;
        return $builder;
    }

    protected function skip(int $skip): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_skip = $skip;
        return $builder;
    }

    // --- Retrieval ---

    protected function exists(): bool
    {
        $checkBuilder = static::query();
        $this->_copyQueryState($this->_getQueryBuilderInstance(), $checkBuilder);

        $checkBuilder->selectRaw('1')->take(1);

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

        $stashTtl = $builder->_stashFor;
        if ($stashTtl === null) {
            $reflection = new \ReflectionClass(static::class);
            $stashAttr = $reflection->getAttributes(Stash::class);
            if (!empty($stashAttr)) {
                $stashTtl = $stashAttr[0]->newInstance()->ttl;
            } else {
                $stashTtl = 0;
            }
        }

        if ($stashTtl > 0) {
            try {
                $cache = ContainerBridge::resolve(Cache::class);
                $cacheKey = $builder->_stashKey ?? $builder->_generateStashKey($sql, $builder->_compiledBindings);
                
                if ($cache->has($cacheKey)) {
                    $results = $cache->get($cacheKey);
                } else {
                    $stmt = $builder->_execute($sql, $builder->_compiledBindings);
                    $results = $stmt->fetchAll();
                    $cache->set($cacheKey, $results, $stashTtl);
                }
            } catch (\Throwable $e) {
                // Fallback if cache fails or is unavailable
                $stmt = $builder->_execute($sql, $builder->_compiledBindings);
                $results = $stmt->fetchAll();
            }
        } else {
            try {
                $stmt = $builder->_execute($sql, $builder->_compiledBindings);
                $results = $stmt->fetchAll();
            } catch (DatabaseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new RuntimeException("Query Error: " . $e->getMessage() . " [SQL: $sql]", 0, $e);
            }
        }

        $models = array_map(fn($row) => static::fromStorage($row), $results ?: []);

        if (!empty($models) && !empty($builder->_includes)) {
            $this->eagerLoadRelations($models, $builder->_includes);
        }

        return new Collection($models);
    }

    protected function all(): Collection
    {
        return $this->get();
    }

    protected function first(): ?static
    {
        $this->take(1);
        $collection = $this->get();
        return $collection->first();
    }

    protected function last(): ?static
    {
        $this->orderBy($this->getPrimaryKey(), 'DESC')->take(1);
        return $this->get()->first();
    }

    protected function find(mixed $id, mixed $includes = []): ?static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->where($this->getPrimaryKey(), $id);
        if ($includes) {
            $includes = is_array($includes) ? $includes : [$includes];
            $builder->include(...$includes);
        }
        return $builder->first();
    }

    protected function findOrFail(mixed $id, array $includes = []): static
    {
        $result = $this->find($id, $includes);
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

        $grammar = $builder->getDialect();
        $columnsStr = implode(', ', array_map([$grammar, 'quote'], $columns));
        $placeholdersStr = implode(', ', $placeholders);
        $tableName = $grammar->quoteTable($table);

        $sql = "INSERT INTO {$tableName} ({$columnsStr}) VALUES {$placeholdersStr}";

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

        $grammar = $builder->getDialect();
        
        if (empty($update)) {
            $update = array_filter($columns, fn($col) => !in_array($col, (array) $uniqueBy));
        }

        $sql = $grammar->buildUpsertQuery($table, $columns, $placeholders, (array) $uniqueBy, $update);

        try {
            $stmt = $builder->_execute($sql, $bindings);
            return $stmt->rowCount();
        } catch (DatabaseException $e) {
            return 0;
        }
    }

    /**
     * Perform a bulk update on the current query builder instance.
     *
     * @param array $values Associative array of column => value
     * @return int Number of affected rows
     */
    public function updateMany(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        $builder = $this->_getQueryBuilderInstance();
        $grammar = $builder->getDialect();
        $table = $grammar->quoteTable($builder->_from ?? $builder->getTable());

        $columns = array_keys($values);
        $bindings = array_values($values);

        // Extract compiled WHERE bindings
        $builder->_compiledBindings = [];
        $builder->_extractBindingsFromWheres($builder->_wheres);
        $whereBindings = $builder->_compiledBindings;

        // Ensure we merge SET bindings before WHERE bindings
        $finalBindings = array_merge($bindings, $whereBindings);

        $sql = $grammar->buildUpdateQuery($builder->_from ?? $builder->getTable(), $columns, $builder->_wheres);

        try {
            $stmt = $builder->_execute($sql, $finalBindings);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
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
        $aggregateBuilder->_take = null;
        $aggregateBuilder->_skip = null;

        $aggregateBuilder->selectRaw("{$function}({$column}) as aggregate");

        $sql = $aggregateBuilder->_buildSelectSQL();

        $stashTtl = $aggregateBuilder->_stashFor;
        if ($stashTtl === null) {
            $reflection = new \ReflectionClass(static::class);
            $stashAttr = $reflection->getAttributes(Stash::class);
            if (!empty($stashAttr)) {
                $stashTtl = $stashAttr[0]->newInstance()->ttl;
            } else {
                $stashTtl = 0;
            }
        }

        if ($stashTtl > 0) {
            try {
                $cache = ContainerBridge::resolve(Cache::class);
                $cacheKey = $aggregateBuilder->_stashKey ?? $aggregateBuilder->_generateStashKey($sql, $aggregateBuilder->_compiledBindings);
                
                if ($cache->has($cacheKey)) {
                    return $cache->get($cacheKey);
                } else {
                    $stmt = $aggregateBuilder->_execute($sql, $aggregateBuilder->_compiledBindings);
                    $result = $stmt->fetchColumn();
                    $cache->set($cacheKey, $result, $stashTtl);
                    return $result;
                }
            } catch (\Throwable $e) {
                $stmt = $aggregateBuilder->_execute($sql, $aggregateBuilder->_compiledBindings);
                return $stmt->fetchColumn();
            }
        }

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

    public function getQueryState(): array
    {
        return [
            'action' => $this->_queryAction,
            'distinct' => $this->_distinct,
            'selects' => $this->_selects,
            'from' => $this->_from ?? $this->getTable(),
            'joins' => $this->_joins,
            'wheres' => $this->getWheresState($this->_wheres),
            'groups' => $this->_groups,
            'havings' => $this->_havings,
            'orders' => $this->_orders,
            'take' => $this->_take,
            'skip' => $this->_skip,
        ];
    }

    private function getWheresState(array $wheres): array
    {
        $state = [];
        foreach ($wheres as $where) {
            if ($where['type'] === 'nested') {
                $where['query'] = ['wheres' => $this->getWheresState($where['query']->_wheres)];
            }
            $state[] = $where;
        }
        return $state;
    }

    private function _buildSelectSQL(): string
    {
        if (!$this->_isQueryBuilderInstance || $this->_queryAction !== 'SELECT') {
            throw new RuntimeException("Invalid query action.");
        }

        $this->_compiledBindings = [];
        
        $this->_extractBindings($this->_selects);
        $this->_extractBindingsFromWheres($this->_wheres);
        $this->_extractBindings($this->_havings);

        return $this->getDialect()->buildSelectQuery($this->getQueryState());
    }

    private function _extractBindings(array $elements): void
    {
        foreach ($elements as $element) {
            if (!empty($element['bindings'])) {
                $this->_compiledBindings = array_merge($this->_compiledBindings, $element['bindings']);
            }
        }
    }

    private function _extractBindingsFromWheres(array $wheres): void
    {
        foreach ($wheres as $where) {
            if ($where['type'] === 'nested') {
                $this->_extractBindingsFromWheres($where['query']->_wheres);
            } elseif (!empty($where['bindings'])) {
                $this->_compiledBindings = array_merge($this->_compiledBindings, $where['bindings']);
            }
        }
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

        $itemBuilder->take($perPage);
        $itemBuilder->skip(($page - 1) * $perPage);
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
        $to->_includes = $from->_includes;
        $to->_stashFor = $from->_stashFor;
        $to->_stashKey = $from->_stashKey;
    }

    // --- Data Processing (Batching & Streaming) ---

    protected function batch(int $count, callable $callback): bool
    {
        $page = 1;

        do {
            $clone = static::query();
            $this->_copyQueryState($this, $clone);
            
            $results = $clone->take($count)->skip(($page - 1) * $count)->get();

            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while ($countResults == $count);

        return true;
    }

    protected function batchByPrimaryKey(int $count, callable $callback, ?string $column = null): bool
    {
        $column = $column ?? $this->getPrimaryKey();
        $lastId = null;
        $page = 1;

        do {
            $clone = static::query();
            $this->_copyQueryState($this, $clone);

            $clone->take($count)->orderBy($column, 'ASC');

            if ($lastId !== null) {
                $clone->where($column, '>', $lastId);
            }

            $results = $clone->get();

            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $lastId = $results->last()->{$column};
            $page++;
        } while ($countResults == $count);

        return true;
    }

    /**
     * @return \Generator<static>
     */
    protected function stream(): \Generator
    {
        $builder = $this->_getQueryBuilderInstance();
        $sql = $builder->_buildSelectSQL();

        try {
            $stmt = $builder->_execute($sql, $builder->_compiledBindings);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $model = static::fromStorage($row);
                
                if (!empty($builder->_includes)) {
                    $this->eagerLoadRelations([$model], $builder->_includes);
                }

                yield $model;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Stream Error: " . $e->getMessage() . " [SQL: $sql]", 0, $e);
        }
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

    // --- Caching (Stash) ---

    protected function stashFor(int $seconds, ?string $key = null): static
    {
        $builder = $this->_getQueryBuilderInstance();
        $builder->_stashFor = $seconds;
        if ($key !== null) {
            $builder->_stashKey = $key;
        }
        return $builder;
    }

    protected function stashForever(?string $key = null): static
    {
        return $this->stashFor(31536000, $key); // Approx 1 year
    }

    public static function dropStash(string $key): void
    {
        try {
            $cache = ContainerBridge::resolve(Cache::class);
            $cache->delete($key);
        } catch (\Throwable $e) {
            // Ignore if cache is not available
        }
    }

    private function _generateStashKey(string $sql, array $bindings): string
    {
        return 'stash_' . md5($sql . serialize($bindings));
    }
}
