<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Strux\Component\Database\Database;
use Strux\Component\Database\Schema\Attributes\Id;
use Strux\Component\Database\Schema\Attributes\Table;
use Strux\Component\Exceptions\DatabaseException;
use Strux\Support\Helpers\Utils;
use Strux\Component\Database\ORM\Attributes\RelationAttribute;
use Strux\Component\Database\ORM\Behavior\HasAttributes;
use Strux\Component\Database\ORM\Behavior\HasEvents;
use Strux\Component\Database\ORM\Behavior\HasQueryBuilder;
use Strux\Component\Database\ORM\Behavior\HasRelationships;
use Strux\Component\Database\ORM\Behavior\HasTimestamps;
use Strux\Component\Database\ORM\Behavior\HasValidation;
use Strux\Component\Database\ORM\Events\Created;
use Strux\Component\Database\ORM\Events\Creating;
use Strux\Component\Database\ORM\Events\Deleted;
use Strux\Component\Database\ORM\Events\Deleting;
use Strux\Component\Database\ORM\Events\Retrieved;
use Strux\Component\Database\ORM\Events\Saved;
use Strux\Component\Database\ORM\Events\Saving;
use Strux\Component\Database\ORM\Events\Updated;
use Strux\Component\Database\ORM\Events\Updating;
use Strux\Support\ContainerBridge;
use Throwable;

abstract class Model
{
    use HasAttributes, HasEvents, HasQueryBuilder, HasRelationships, HasTimestamps, HasValidation;

    protected ?PDO $db = null;
    private ?string $_tableName = null;
    private ?string $_primaryKeyName = null;
    private bool $_exists = false;

    private static array $globalScopes = [];
    private array $removedScopes = [];
    private static int $transactionLevel = 0;

    /**
     * @throws ReflectionException
     */
    public function __construct(array $attributes = [])
    {
        $this->resolveConnection();

        $this->bootTraits();

        $this->fill($attributes);

        $pk = $this->getPrimaryKey();
        if (!empty($attributes) && isset($attributes[$pk])) {
            $this->_exists = true;
            $this->_original = $attributes;
        }
        $this->_isQueryBuilderInstance = false;
    }

    private function resolveConnection(): void
    {
        try {
            $this->db = ContainerBridge::resolve(PDO::class);
        } catch (Throwable $e) {
            error_log("Model Constructor: Failed to resolve PDO: " . $e->getMessage());
        }
    }

    protected function bootTraits(): void
    {
        $class = static::class;
        foreach (class_uses_recursive($class) as $trait) {
            $method = 'initialize' . class_basename($trait);
            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }
    }

    // --- State Accessors ---

    /**
     * Determine if the model exists in the database.
     */
    public function exists(): bool
    {
        return $this->_exists;
    }

    /**
     * Determine if the model is a new, unsaved record.
     */
    public function isNew(): bool
    {
        return !$this->_exists;
    }

    // --- Lifecycle Hooks ---
    protected function beforeSave(): void {}
    protected function afterSave(): void {}
    protected function beforeCreate(): void {}
    protected function afterCreate(): void {}
    protected function beforeUpdate(): void {}
    protected function afterUpdate(): void {}
    protected function beforeDelete(): void {}
    protected function afterDelete(): void {}

    /**
     * Create a new record and save it to the database.
     */
    public static function create(array $attributes = []): static
    {
        $instance = new static();
        try {
            $instance->fill($attributes);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Failed to create model: " . $e->getMessage(), 0, $e);
        }
        $instance->save();
        return $instance;
    }

    /**
     * Handle dynamic static method calls.
     */
    public static function __callStatic(string $method, array $parameters)
    {
        return static::query()->$method(...$parameters);
    }

    /**
     * Update a record by its primary key.
     * * @param mixed $id The primary key value
     * @param array $attributes The attributes to update
     * @return static|null The updated model instance or null if not found
     */
    public static function update(mixed $id, array $attributes): ?static
    {
        $instance = static::find($id);

        if (!$instance) {
            return null;
        }

        try {
            $instance->fill($attributes);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Failed to update model: " . $e->getMessage(), 0, $e);
        }
        $instance->save();

        return $instance;
    }

    public static function fromStorage(array $data): static
    {
        $instance = new static();
        try {
            $instance->fill($data);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Failed to instantiate model from storage: " . $e->getMessage(), 0, $e);
        }
        $instance->_exists = true;
        $instance->_original = $data;
        $instance->_isQueryBuilderInstance = false;

        $instance->fireModelEvent(new Retrieved($instance));

        return $instance;
    }

    public function applyGlobalScopes(Model $instance): void
    {
        if (isset(static::$globalScopes[static::class])) {
            foreach (static::$globalScopes[static::class] as $scope => $implementation) {
                if (!in_array($scope, $instance->removedScopes)) {
                    $implementation($instance);
                }
            }
        }
    }

    public function __get(string $key)
    {
        if (array_key_exists($key, $this->_relations)) {
            return $this->_relations[$key];
        }

        if (array_key_exists($key, $this->_original)) {
            return $this->_original[$key];
        }

        if (property_exists($this, $key)) {
            $prop = new ReflectionProperty($this, $key);
            if ($prop->isPublic()) {
                $attributes = $prop->getAttributes(RelationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
                if (!empty($attributes)) {
                    $relation = $this->initializeRelationFromAttribute($attributes[0]);
                    $result = $relation->getResults();
                    $this->setRelation($key, $result);
                    return $result;
                }
                if ($prop->isInitialized($this))
                    return $this->{$key};
            }
        }

        throw new RuntimeException("Property '$key' does not exist on " . static::class);
    }

    public function getTable(): string
    {
        if ($this->_tableName !== null)
            return $this->_tableName;
        $attributes = $this->reflection()->getAttributes(Table::class);
        if (!empty($attributes))
            return $this->_tableName = $attributes[0]->newInstance()->name;
        $className = $this->reflection()->getShortName();
        return $this->_tableName = strtolower(preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $className)) . 's';
    }

    public function getPrimaryKey(): string
    {
        if ($this->_primaryKeyName !== null)
            return $this->_primaryKeyName;
        foreach ($this->reflection()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!empty($property->getAttributes(Id::class))) {
                return $this->_primaryKeyName = $property->getName();
            }
        }
        return $this->_primaryKeyName = 'id';
    }

    protected function reflection(): ReflectionClass
    {
        static $reflectionCache = [];
        $class = static::class;
        if (!isset($reflectionCache[$class])) {
            $reflectionCache[$class] = new ReflectionClass($this);
        }
        return $reflectionCache[$class];
    }

    // --- Persistence Methods ---

    /**
     * @throws DatabaseException
     */
    private function _execute(string $sql, array $bindings = []): PDOStatement
    {
        $action = strtoupper(strtok(ltrim($sql), " \t\n\r\0\x0B("));
        $type = in_array($action, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN']) ? 'read' : 'write';

        if (self::$transactionLevel > 0) {
            $type = 'write';
        }

        try {
            /** @var Database $dbManager */
            $dbManager = ContainerBridge::resolve(Database::class);
            $pdo = $dbManager->getConnection($type);
        } catch (Throwable $e) {
            $pdo = $this->db;
        }

        if ($pdo === null) {
            throw new DatabaseException("PDO connection not available in model " . static::class);
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt;
        } catch (PDOException $e) {
            error_log("DB Exec Error: " . $e->getMessage() . " SQL: $sql");
            throw new DatabaseException("Query failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function save(): bool
    {
        if ($this->_isQueryBuilderInstance)
            throw new RuntimeException("Cannot call save() on query builder.");

        $this->beforeSave();

        if (!$this->validate()) {
            return false;
        }

        $this->fireModelEvent(new Saving($this));

        $attributes = $this->_getPublicPropertiesForDb();
        $this->handleTimestamps($attributes);

        if ($this->_exists) {
            $success = $this->_performUpdate($attributes);
        } else {
            $success = $this->_performInsert($attributes);
        }

        if ($success) {
            $this->_original = $this->_getPublicPropertiesForDb();
            $this->fireModelEvent(new Saved($this));
            $this->afterSave();
        }
        return $success;
    }

    /**
     * @throws DatabaseException
     */
    protected function _performUpdate(array $attributes): bool
    {
        $prepared = [];
        foreach ($attributes as $key => $value) {
            if (is_bool($value)) {
                $prepared[$key] = $value ? 1 : 0;
            } elseif (is_scalar($value) || is_null($value)) {
                $prepared[$key] = $value;
            } elseif (is_array($value)) {
                $prepared[$key] = json_encode($value);
            } elseif ($value instanceof \DateTimeInterface) {
                $prepared[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        $attributesToSave = $prepared;
        $dirty = [];

        foreach ($attributesToSave as $key => $value) {
            if ($key !== $this->getPrimaryKey() && (!array_key_exists($key, $this->_original) || $this->_original[$key] !== $value)) {
                $dirty[$key] = $value;
            }
        }

        if (empty($dirty))
            return true;

        $this->fireModelEvent(new Updating($this));

        $pkValue = $this->{$this->getPrimaryKey()} ?? null;
        if ($pkValue === null)
            throw new RuntimeException("Cannot update without primary key.");

        $bindings = array_values($dirty);
        $bindings[] = $pkValue;

        $grammar = $this->getDialect();
        $sql = $grammar->buildUpdateQuery($this->getTable(), array_keys($dirty));
        $sql .= " WHERE " . $grammar->quote($this->getPrimaryKey()) . " = ?";
        $stmt = $this->_execute($sql, $bindings);
        $success = $stmt->rowCount() >= 0;

        if ($success) {
            $this->fireModelEvent(new Updated($this));
            $this->afterUpdate();
        }

        return $success;
    }

    /**
     * @throws DatabaseException
     */
    protected function _performInsert(array $attributes): bool
    {
        $prepared = [];
        foreach ($attributes as $key => $value) {
            if (is_bool($value)) {
                $prepared[$key] = $value ? 1 : 0;
            } elseif (is_scalar($value) || is_null($value)) {
                $prepared[$key] = $value;
            } elseif (is_array($value)) {
                $prepared[$key] = json_encode($value);
            } elseif ($value instanceof \DateTimeInterface) {
                $prepared[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        $attributesToSave = $prepared;
        $pkName = $this->getPrimaryKey();

        if (array_key_exists($pkName, $attributesToSave) && $attributesToSave[$pkName] === null) {
            $pkProperty = $this->reflection()->getProperty($pkName);
            $idAttr = ($pkProperty->getAttributes(Id::class)[0] ?? null)?->newInstance();

            if ($idAttr && $idAttr->autoGenerate !== 'none') {
                $type = $pkProperty->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $typeName = $type->getName();
                    if (!in_array($typeName, ['string', 'mixed', 'self', 'parent'], true)) {
                        throw new RuntimeException(
                            sprintf(
                                "Cannot auto-generate a %s for field '%s': expected string-compatible type, got %s.",
                                $idAttr->autoGenerate,
                                $pkName,
                                $typeName
                            )
                        );
                    }
                }

                $generated = match ($idAttr->autoGenerate) {
                    'uuid' => Utils::uuid(),
                    'ulid' => Utils::ulid(),
                    default => throw new RuntimeException("Unknown autoGenerate option: {$idAttr->autoGenerate}")
                };

                $this->{$pkName} = $generated;
                $attributesToSave[$pkName] = $generated;
            } else {
                unset($attributesToSave[$pkName]);
            }
        }

        if (empty($attributesToSave))
            return false;

        $this->fireModelEvent(new Creating($this));

        $columns = array_keys($attributesToSave);
        $placeholders = array_fill(0, count($columns), '?');
        $bindings = array_values($attributesToSave);

        $grammar = $this->getDialect();
        $sql = $grammar->buildInsertQuery($this->getTable(), $columns, $placeholders);
        $this->_execute($sql, $bindings);

        $id = $this->db->lastInsertId();
        if ($id && property_exists($this, $this->getPrimaryKey())) {
            if (!isset($attributesToSave[$this->getPrimaryKey()]) || $attributesToSave[$this->getPrimaryKey()] === null) {
                $this->{$this->getPrimaryKey()} = is_numeric($id) ? (int) $id : $id;
            }
        }
        $this->_exists = true;

        $this->fireModelEvent(new Created($this));

        return true;
    }

    public function delete(): bool
    {
        if ($this->_isQueryBuilderInstance)
            throw new RuntimeException("Cannot call delete() on query builder.");
        if (!$this->_exists)
            return false;

        $this->fireModelEvent(new Deleting($this));

        $grammar = $this->getDialect();
        $sql = $grammar->buildDeleteQuery($this->getTable()) . " WHERE " . $grammar->quote($this->getPrimaryKey()) . " = ?";
        $stmt = $this->_execute($sql, [$this->{$this->getPrimaryKey()}]);

        if ($stmt->rowCount() > 0) {
            $this->_exists = false;
            $this->fireModelEvent(new Deleted($this));
            return true;
        }
        return false;
    }

    public static function destroy(mixed $ids): int
    {
        $instance = static::query();
        $ids = is_array($ids) ? $ids : [$ids];
        if (empty($ids))
            return 0;

        $grammar = $instance->getDialect();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = $grammar->buildDeleteQuery($instance->getTable()) . " WHERE " . $grammar->quote($instance->getPrimaryKey()) . " IN ($placeholders)";
        try {
            $stmt = $instance->_execute($sql, $ids);
        } catch (DatabaseException $e) {
            throw new RuntimeException("An error occurred while destroying records: " . $e->getMessage());
        }
        return $stmt->rowCount();
    }

    // --- Transaction Management ---

    /**
     * Execute a Closure within a database transaction.
     *
     * @param Closure $callback
     * @return mixed
     * @throws Throwable
     */
    public static function transaction(Closure $callback): mixed
    {
        static::beginTransaction();

        try {
            $result = $callback();
            static::commit();
            return $result;
        } catch (Throwable $e) {
            static::rollBack();
            throw $e;
        }
    }

    /**
     * Start a new database transaction.
     */
    public static function beginTransaction(): void
    {
        try {
            /** @var Database $dbManager */
            $dbManager = ContainerBridge::resolve(Database::class);
            $pdo = $dbManager->getConnection('write');
        } catch (Throwable $e) {
            /** @var PDO $pdo */
            $pdo = ContainerBridge::resolve(PDO::class);
        }

        if (self::$transactionLevel === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec("SAVEPOINT trans_" . self::$transactionLevel);
        }

        self::$transactionLevel++;
    }

    /**
     * Commit the active database transaction.
     */
    public static function commit(): void
    {
        try {
            /** @var Database $dbManager */
            $dbManager = ContainerBridge::resolve(Database::class);
            $pdo = $dbManager->getConnection('write');
        } catch (Throwable $e) {
            /** @var PDO $pdo */
            $pdo = ContainerBridge::resolve(PDO::class);
        }

        if (self::$transactionLevel === 0) {
            return;
        }

        self::$transactionLevel--;

        if (self::$transactionLevel === 0) {
            $pdo->commit();
        } else {
            try {
                $pdo->exec("RELEASE SAVEPOINT trans_" . self::$transactionLevel);
            } catch (\Exception $e) {
                // Ignored
            }
        }
    }

    /**
     * Rollback the active database transaction.
     */
    public static function rollBack(): void
    {
        try {
            /** @var Database $dbManager */
            $dbManager = ContainerBridge::resolve(Database::class);
            $pdo = $dbManager->getConnection('write');
        } catch (Throwable $e) {
            /** @var PDO $pdo */
            $pdo = ContainerBridge::resolve(PDO::class);
        }

        if (self::$transactionLevel === 0) {
            return;
        }

        self::$transactionLevel--;

        if (self::$transactionLevel === 0) {
            $pdo->rollBack();
        } else {
            $pdo->exec("ROLLBACK TO SAVEPOINT trans_" . self::$transactionLevel);
        }
    }

    public function getLastInsertId(?string $name = null): string|false
    {
        return $this->db->lastInsertId($name);
    }

    public function __sleep(): array
    {
        $properties = (new ReflectionClass($this))->getProperties();
        $propertiesToSerialize = [];
        foreach ($properties as $property) {
            if ($property->getName() !== 'db' && !$property->isStatic()) {
                $propertiesToSerialize[] = $property->getName();
            }
        }
        return $propertiesToSerialize;
    }

    /**
     * @throws DatabaseException
     */
    public function __wakeup(): void
    {
        try {
            $this->db = ContainerBridge::resolve(PDO::class);
        } catch (Throwable $e) {
            throw new DatabaseException("Failed to re-establish PDO connection on model wakeup: " . $e->getMessage());
        }
    }
}
