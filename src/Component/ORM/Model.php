<?php

declare(strict_types=1);

namespace Strux\Component\ORM;

use Closure;
use DateTime;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Strux\Component\Database\Attributes\Id;
use Strux\Component\Database\Attributes\Table;
use Strux\Component\Exceptions\DatabaseException;
use Strux\Component\ORM\Attributes\RelationAttribute;
use Strux\Component\ORM\Behavior\HasAttributes;
use Strux\Component\ORM\Behavior\HasEvents;
use Strux\Component\ORM\Behavior\HasQueryBuilder;
use Strux\Component\ORM\Behavior\HasRelationships;
use Strux\Component\ORM\Behavior\HasTimestamps;
use Strux\Component\ORM\Behavior\HasValidation;
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

        $instance->fireModelEvent(new \Strux\Component\ORM\Events\Retrieved($instance));

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

    private function reflection(): ReflectionClass
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
        if ($this->db === null) {
            throw new DatabaseException("PDO connection not available in model " . static::class);
        }
        try {
            $stmt = $this->db->prepare($sql);
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

        $this->fireModelEvent(new \Strux\Component\ORM\Events\Saving($this));

        $attributes = $this->_getPublicPropertiesForDb();
        $this->handleTimestamps($attributes);

        if ($this->_exists) {
            $success = $this->_performUpdate($attributes);
        } else {
            $success = $this->_performInsert($attributes);
        }

        if ($success) {
            $this->_original = $this->_getPublicPropertiesForDb();
            $this->fireModelEvent(new \Strux\Component\ORM\Events\Saved($this));
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
            if (is_scalar($value) || is_null($value)) {
                $prepared[$key] = $value;
            } elseif (is_array($value)) {
                $prepared[$key] = json_encode($value);
            } elseif ($value instanceof DateTime) {
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

        $this->fireModelEvent(new \Strux\Component\ORM\Events\Updating($this));

        $pkValue = $this->{$this->getPrimaryKey()} ?? null;
        if ($pkValue === null)
            throw new RuntimeException("Cannot update without primary key.");

        $setClauses = array_map(fn($col) => "`{$col}` = ?", array_keys($dirty));
        $bindings = array_values($dirty);
        $bindings[] = $pkValue;

        $sql = "UPDATE `{$this->getTable()}` SET " . implode(', ', $setClauses) . " WHERE `{$this->getPrimaryKey()}` = ?";
        $stmt = $this->_execute($sql, $bindings);
        $success = $stmt->rowCount() >= 0;

        if ($success) {
            $this->fireModelEvent(new \Strux\Component\ORM\Events\Updated($this));
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
            if (is_scalar($value) || is_null($value)) {
                $prepared[$key] = $value;
            } elseif (is_array($value)) {
                $prepared[$key] = json_encode($value);
            } elseif ($value instanceof DateTime) {
                $prepared[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        $attributesToSave = $prepared;

        if (array_key_exists($this->getPrimaryKey(), $attributesToSave) && $attributesToSave[$this->getPrimaryKey()] === null) {
            unset($attributesToSave[$this->getPrimaryKey()]);
        }

        if (empty($attributesToSave))
            return false;

        $this->fireModelEvent(new \Strux\Component\ORM\Events\Creating($this));

        $columns = array_keys($attributesToSave);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $bindings = array_values($attributesToSave);

        $sql = "INSERT INTO `{$this->getTable()}` (`" . implode('`, `', $columns) . "`) VALUES ($placeholders)";
        $this->_execute($sql, $bindings);

        $id = $this->db->lastInsertId();
        if ($id && property_exists($this, $this->getPrimaryKey())) {
            if (!isset($attributesToSave[$this->getPrimaryKey()]) || $attributesToSave[$this->getPrimaryKey()] === null) {
                $this->{$this->getPrimaryKey()} = is_numeric($id) ? (int) $id : $id;
            }
        }
        $this->_exists = true;

        $this->fireModelEvent(new \Strux\Component\ORM\Events\Created($this));

        return true;
    }

    public function delete(): bool
    {
        if ($this->_isQueryBuilderInstance)
            throw new RuntimeException("Cannot call delete() on query builder.");
        if (!$this->_exists)
            return false;

        $this->fireModelEvent(new \Strux\Component\ORM\Events\Deleting($this));

        $sql = "DELETE FROM `{$this->getTable()}` WHERE `{$this->getPrimaryKey()}` = ?";
        $stmt = $this->_execute($sql, [$this->{$this->getPrimaryKey()}]);

        if ($stmt->rowCount() > 0) {
            $this->_exists = false;
            $this->fireModelEvent(new \Strux\Component\ORM\Events\Deleted($this));
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

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM `{$instance->getTable()}` WHERE `{$instance->getPrimaryKey()}` IN ($placeholders)";
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
        /* @var PDO $pdo */
        $pdo = ContainerBridge::resolve(PDO::class);
        if ($pdo->inTransaction()) {
            return;
        }
        $pdo->beginTransaction();
    }

    /**
     * Commit the active database transaction.
     */
    public static function commit(): void
    {
        /* @var PDO $pdo */
        $pdo = ContainerBridge::resolve(PDO::class);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    /**
     * Rollback the active database transaction.
     */
    public static function rollBack(): void
    {
        /* @var PDO $pdo */
        $pdo = ContainerBridge::resolve(PDO::class);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
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
