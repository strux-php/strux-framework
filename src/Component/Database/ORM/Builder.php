<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM;

use Closure;
use Generator;
use Strux\Component\Database\Paginator;
use Strux\Support\Collection;

/**
 * @method Builder from(string $table)
 * @method Builder select(array|string $columns = ['*'])
 * @method Builder selectRaw(string $expression, array $bindings = [])
 * @method Builder distinct()
 * @method Builder where(array|string|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method Builder orWhere(string|Closure $column, mixed $operator = null, mixed $value = null)
 * @method Builder whereNot(Closure $callback)
 * @method Builder orWhereNot(Closure $callback)
 * @method Builder whereRaw(string $sql, array $bindings = [], string $boolean = 'AND')
 * @method Builder orWhereRaw(string $sql, array $bindings = [])
 * @method Builder whereIn(string $column, array|Closure $values, string $boolean = 'AND', bool $not = false)
 * @method Builder whereNotIn(string $column, array $values, string $boolean = 'AND')
 * @method Builder whereAny(array $columns, string $operator, mixed $value)
 * @method Builder whereAll(array $columns, string $operator, mixed $value)
 * @method Builder whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND', bool $not = false)
 * @method Builder orWhereLike(string $column, string $value, bool $caseSensitive = false)
 * @method Builder whereNotLike(string $column, string $value, bool $caseSensitive = false)
 * @method Builder orWhereNotLike(string $column, string $value, bool $caseSensitive = false)
 * @method Builder whereJson(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND')
 * @method Builder orWhereJson(string $column, mixed $operator, mixed $value = null)
 * @method Builder join(string $table, string $first, mixed $operatorOrSecond, mixed $second = null)
 * @method Builder leftJoin(string $table, string $first, ?string $operatorOrSecond = null, ?string $second = null)
 * @method Builder rightJoin(string $table, string $first, ?string $operatorOrSecond = null, ?string $second = null)
 * @method Builder orderBy(string $column, string $direction = 'ASC')
 * @method Builder latest(?string $column = null)
 * @method Builder oldest(?string $column = null)
 * @method Builder groupBy(string ...$columns)
 * @method Builder having(string $column, string $operator, mixed $value, string $boolean = 'AND')
 * @method Builder take(int $count)
 * @method Builder skip(int $count)
 * @method Builder with(mixed ...$relations)
 * @method Builder without(mixed ...$relations)
 * @method Builder stashFor(int $seconds, ?string $key = null)
 * @method Builder stashForever(?string $key = null)
 * @method Builder all()
 * @method Collection get()
 * @method Model|null first()
 * @method Model|null last()
 * @method Model|null find(mixed $id, mixed $includes = [])
 * @method Model findOrFail(mixed $id, array $includes = [])
 * @method bool exists()
 * @method int count(string $column = '*')
 * @method mixed max(string $column)
 * @method mixed min(string $column)
 * @method mixed avg(string $column)
 * @method mixed sum(string $column)
 * @method string toSql()
 * @method string toRawSql()
 * @method Paginator paginate(int $perPage = 15, array $columns = ['*'], string $pageName = 'page', ?int $page = null, ?string $path = '', array $query = [])
 * @method Generator stream()
 * @method bool batch(int $count, callable $callback)
 * @method bool batchByPrimaryKey(int $count, callable $callback, ?string $column = null)
 * @method int insert(array $values)
 * @method int upsert(array $values, array|string $uniqueBy, ?array $update = null)
 * @method int updateMany(array $values)
 * @method array getQueryState()
 */
interface Builder
{
}
