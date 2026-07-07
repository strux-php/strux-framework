<?php

declare(strict_types=1);

namespace Strux\Support\Bridge;

use Strux\Component\Database\ORM\Builder;
use Strux\Support\FrameworkBridge;

/**
 * @method static Builder table(string $table)
 * @method static Builder from(string $table)
 * @method static Builder select(array|string $columns = ['*'])
 * @method static Builder selectRaw(string $expression, array $bindings = [])
 * @method static Builder distinct()
 * @method static Builder where(array|string|\Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static Builder orWhere(string|\Closure $column, mixed $operator = null, mixed $value = null)
 * @method static Builder whereNot(\Closure $callback)
 * @method static Builder orWhereNot(\Closure $callback)
 * @method static Builder whereRaw(string $sql, array $bindings = [], string $boolean = 'AND')
 * @method static Builder orWhereRaw(string $sql, array $bindings = [])
 * @method static Builder whereIn(string $column, array|\Closure $values, string $boolean = 'AND', bool $not = false)
 * @method static Builder whereNotIn(string $column, array $values, string $boolean = 'AND')
 * @method static Builder whereAny(array $columns, string $operator, mixed $value)
 * @method static Builder whereAll(array $columns, string $operator, mixed $value)
 * @method static Builder whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND', bool $not = false)
 * @method static Builder orWhereLike(string $column, string $value, bool $caseSensitive = false)
 * @method static Builder whereNotLike(string $column, string $value, bool $caseSensitive = false)
 * @method static Builder orWhereNotLike(string $column, string $value, bool $caseSensitive = false)
 * @method static Builder whereJson(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND')
 * @method static Builder orWhereJson(string $column, mixed $operator, mixed $value = null)
 * @method static Builder join(string $table, string $first, mixed $operatorOrSecond, mixed $second = null)
 * @method static Builder leftJoin(string $table, string $first, ?string $operatorOrSecond = null, ?string $second = null)
 * @method static Builder rightJoin(string $table, string $first, ?string $operatorOrSecond = null, ?string $second = null)
 * @method static Builder orderBy(string $column, string $direction = 'ASC')
 * @method static Builder latest(?string $column = null)
 * @method static Builder oldest(?string $column = null)
 * @method static Builder groupBy(string ...$columns)
 * @method static Builder having(string $column, string $operator, mixed $value, string $boolean = 'AND')
 * @method static Builder take(int $count)
 * @method static Builder skip(int $count)
 * @method static Builder with(mixed ...$relations)
 * @method static Builder without(mixed ...$relations)
 * @method static Builder stashFor(int $seconds, ?string $key = null)
 * @method static Builder stashForever(?string $key = null)
 * @method static \Strux\Support\Collection get()
 * @method static Model|null first()
 * @method static Model|null last()
 * @method static Model|null find(mixed $id, mixed $includes = [])
 * @method static Builder findOrFail(mixed $id, array $includes = [])
 * @method static bool exists()
 * @method static int count(string $column = '*')
 * @method static mixed max(string $column)
 * @method static mixed min(string $column)
 * @method static mixed avg(string $column)
 * @method static mixed sum(string $column)
 * @method static string toSql()
 * @method static string toRawSql()
 * @method static \Strux\Component\Database\Paginator paginate(int $perPage = 15, array $columns = ['*'], string $pageName = 'page', ?int $page = null, ?string $path = '', array $query = [])
 * @method static \Generator stream()
 * @method static bool batch(int $count, callable $callback)
 * @method static bool batchByPrimaryKey(int $count, callable $callback, ?string $column = null)
 * @method static int insert(array $values)
 * @method static int upsert(array $values, array|string $uniqueBy, ?array $update = null)
 * @method static int updateMany(array $values)
 * @method static array getQueryState()
 * @method static Builder query()
 * @see \Strux\Component\Database\ORM\Adhoc
 */
class DB extends FrameworkBridge
{
	protected static function getAccessor(): string
	{
		return 'db.query';
	}
}
