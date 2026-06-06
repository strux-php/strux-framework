<?php

namespace Strux\Component\Database\Migration;

use BackedEnum;
use PDO;
use ReflectionClass;
use ReflectionException;
use Strux\Component\Database\Schema\Attributes\Column;
use Strux\Component\Database\Schema\Attributes\Id;
use Strux\Component\Database\Schema\Attributes\Table;
use Strux\Component\Database\Schema\Attributes\Unique;
use Strux\Component\Database\Schema\Attributes\Index;
use Strux\Component\Database\Schema\Types\Field;
use Strux\Component\Database\ORM\Attributes\OwnedBy;
use Strux\Component\Database\ORM\Attributes\OwnedByMany;
use Strux\Support\Helpers\Utils;

class Blueprint
{
    /**
     * @throws ReflectionException
     */
    public static function generateIndexes(string $modelClass, PDO $db): array
    {
        $reflection = new ReflectionClass($modelClass);
        $tableAttribute = $reflection->getAttributes(Table::class)[0] ?? null;

        if (!$tableAttribute) {
            return [];
        }

        $tableName = $tableAttribute->newInstance()->name;
        $sql = [];

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dialect = match ($driver) {
            'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
            'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
            'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
            'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
            default => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
        };

        // 1. Process Class-level #[Index] attributes (Composite Indexes)
        foreach ($reflection->getAttributes(Index::class) as $attr) {
            $instance = $attr->newInstance();
            $columns = is_array($instance->columns) ? $instance->columns : [$instance->columns];
            
            $defaultName = $tableName . '_' . implode('_', $columns) . '_idx';
            $indexName = $instance->name ?? $defaultName;
            
            $sql[$indexName] = $dialect->buildAddIndexQuery($tableName, $indexName, $columns, $instance->unique);
        }

        // 2. Process Property-level attributes
        foreach ($reflection->getProperties() as $property) {
            $columnAttr = $property->getAttributes(Column::class)[0] ?? null;
            $uniqueAttr = $property->getAttributes(Unique::class)[0] ?? null;
            $indexAttr = $property->getAttributes(Index::class)[0] ?? null;

            if ($columnAttr || $indexAttr) {
                $colInstance = $columnAttr?->newInstance();
                $columnName = $colInstance->name ?? $property->getName();

                $isUnique = ($uniqueAttr !== null) || ($colInstance && $colInstance->unique) || ($indexAttr && $indexAttr->newInstance()->unique);
                $isIndexed = $isUnique || ($indexAttr !== null);

                if ($isIndexed) {
                    $manualIndexName = $uniqueAttr?->newInstance()->indexName ?? $indexAttr?->newInstance()->name;
                    $suffix = $isUnique ? 'unique' : 'idx';
                    $indexName = $manualIndexName ?? "{$tableName}_{$columnName}_{$suffix}";

                    $sql[$indexName] = $dialect->buildAddIndexQuery($tableName, $indexName, [$columnName], $isUnique);
                }
            }
        }

        return $sql;
    }

    /**
     * @throws ReflectionException
     */
    public static function generateForeignKeyConstraints(string $modelClass, PDO $db): array
    {
        $reflection = new ReflectionClass($modelClass);
        $tableAttribute = $reflection->getAttributes(Table::class)[0] ?? null;

        if (!$tableAttribute) {
            return [];
        }

        $tableName = $tableAttribute->newInstance()->name;

        // 1. Fetch existing constraints to avoid duplicates
        $existingConstraints = self::getTableConstraints($db, $tableName);

        $sql = [];
        $processedColumns = [];

        foreach ($reflection->getProperties() as $property) {
            $ownedByAttr = $property->getAttributes(OwnedBy::class)[0] ?? null;

            if ($ownedByAttr) {
                /** @var OwnedBy $instance */
                $instance = $ownedByAttr->newInstance();

                $relatedClass = $instance->related;

                $propName = $property->getName();
                $defaultFk = $propName;
                if (!str_ends_with($propName, 'ID') && !str_ends_with($propName, 'Id') && !str_ends_with($propName, 'id')) {
                    $defaultFk = $propName . '_id';
                }

                $foreignKeyColumn = $instance->foreignKey ?? $defaultFk;
                $ownerKeyColumn = $instance->ownerKey ?? 'id';

                $onDeleteRaw = $instance->onDelete;
                $onDelete = $onDeleteRaw instanceof BackedEnum ? $onDeleteRaw->value : $onDeleteRaw;

                $onUpdateRaw = $instance->onUpdate;
                $onUpdate = $onUpdateRaw instanceof BackedEnum ? $onUpdateRaw->value : $onUpdateRaw;

                $onDelete = strtoupper($onDelete);
                $onUpdate = strtoupper($onUpdate);

                if (in_array($foreignKeyColumn, $processedColumns)) continue;

                if (class_exists($relatedClass)) {
                    $relatedReflection = new ReflectionClass($relatedClass);
                    $relatedTableAttr = $relatedReflection->getAttributes(Table::class)[0] ?? null;

                    if ($relatedTableAttr) {
                        $relatedTable = $relatedTableAttr->newInstance()->name;
                        $constraintName = "fk_{$tableName}_{$foreignKeyColumn}";

                        // 2. Skip if constraint already exists
                        if (in_array($constraintName, $existingConstraints)) {
                            continue;
                        }

                        $sql[$constraintName] = sprintf(
                        /** @lang text */
                            "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s",
                            $tableName,
                            $constraintName,
                            $foreignKeyColumn,
                            $relatedTable,
                            $ownerKeyColumn,
                            $onDelete,
                            $onUpdate
                        );

                        $processedColumns[] = $foreignKeyColumn;
                    }
                }
            }
        }

        return $sql;
    }

    /**
     * @throws ReflectionException
     */
    public static function generatePivotTableSql(string $modelClass, PDO $db, array $dbConfig = []): array
    {
        $reflection = new ReflectionClass($modelClass);
        $tableAttribute = $reflection->getAttributes(Table::class)[0] ?? null;
        if (!$tableAttribute) return [];

        $currentTable = $tableAttribute->newInstance()->name;
        $sql = [];

        $engine = $dbConfig['engine'] ?: 'InnoDB';
        $charset = $dbConfig['charset'] ?? 'utf8mb4';
        $collation = $dbConfig['collation'] ?? 'utf8mb4_unicode_ci';

        foreach ($reflection->getProperties() as $property) {
            $attr = $property->getAttributes(OwnedByMany::class)[0] ?? null;
            if (!$attr) continue;

            /** @var OwnedByMany $instance */
            $instance = $attr->newInstance();

            $relatedClass = $instance->related;
            if (!class_exists($relatedClass)) continue;

            $relatedReflection = new ReflectionClass($relatedClass);
            $relatedTableAttr = $relatedReflection->getAttributes(Table::class)[0] ?? null;
            $relatedTable = $relatedTableAttr ? $relatedTableAttr->newInstance()->name : null;

            // --- PIVOT TABLE NAME LOGIC ---
            $pivotTableInput = $instance->pivotTable;
            $pivotTable = null;
            $isExplicitModel = false;

            if ($pivotTableInput) {
                // Check if the input is a class that exists
                if (class_exists($pivotTableInput)) {
                    $pivotReflection = new ReflectionClass($pivotTableInput);
                    $pivotTableAttr = $pivotReflection->getAttributes(Table::class)[0] ?? null;
                    if ($pivotTableAttr) {
                        $pivotTable = $pivotTableAttr->newInstance()->name;
                        $isExplicitModel = true;
                    }
                } else {
                    // It's just a string name
                    $pivotTable = $pivotTableInput;
                }
            }

            if (!$pivotTable) {
                // Default convention: alphabetical order of singular model names (or table names)
                $models = [
                    $currentTable ?? Utils::getPluralName($reflection->getShortName()),
                    $relatedTable ?? Utils::getPluralName($relatedReflection->getShortName())
                ];
                sort($models);
                $pivotTable = implode('_', $models);
            }

            // If the pivot table is defined via an explicit Model class, we SKIP generating the CREATE TABLE here.
            // The standard ModelBuilder loop in MigrationGenerator will handle creating the table
            // based on that Model's properties.
            if ($isExplicitModel) {
                continue;
            }

            // --- RESOLVE PIVOT KEYS ---
            // Priority:
            // 1. Explicitly defined in attribute (foreignPivotKey)
            // 2. The Primary Key name of the model (e.g. 'student_number')
            // 3. Fallback convention: model_name + _id (e.g. 'student_id')

            $modelPkName = self::getPrimaryKeyName($modelClass);
            $relatedPkName = self::getPrimaryKeyName($relatedClass);

            $foreignPivotKey = $instance->foreignPivotKey
                ?? (strtolower($reflection->getShortName()) . '_id');

            $relatedPivotKey = $instance->relatedPivotKey
                ?? (strtolower($relatedReflection->getShortName()) . '_id');

            $fk1Type = self::getPrimaryKeyType($modelClass, $db);
            $fk2Type = self::getPrimaryKeyType($relatedClass, $db);

            if (!self::tableExists($db, $pivotTable)) {
                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                $dialect = match ($driver) {
                    'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                    'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                    'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                    'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                    default => throw new \Exception("Unsupported database driver: $driver"),
                };

                $columns = [
                    "`{$foreignPivotKey}` $fk1Type NOT NULL",
                    "`{$relatedPivotKey}` $fk2Type NOT NULL"
                ];

                $sql[$pivotTable] = $dialect->buildCreateTableQuery($pivotTable, $columns, [
                    'engine' => $engine,
                    'charset' => $charset,
                    'collation' => $collation
                ]);
            } else {
                $existingCols = self::getTableColumns($db, $pivotTable);
                $existingConstraints = self::getTableConstraints($db, $pivotTable);

                if (isset($existingCols[$foreignPivotKey])) {
                    if (self::needsModification($existingCols[$foreignPivotKey], $fk1Type)) {
                        $constraintName = "fk_{$pivotTable}_{$foreignPivotKey}";
                        if (in_array($constraintName, $existingConstraints)) {
                            $sql["{$pivotTable}_drop_fk1"] = "ALTER TABLE `{$pivotTable}` DROP FOREIGN KEY `{$constraintName}`;";
                        }
                        $sql["{$pivotTable}_mod1"] = "ALTER TABLE `{$pivotTable}` MODIFY COLUMN `{$foreignPivotKey}` $fk1Type NOT NULL;";
                    }
                }

                if (isset($existingCols[$relatedPivotKey])) {
                    if (self::needsModification($existingCols[$relatedPivotKey], $fk2Type)) {
                        $constraintName = "fk_{$pivotTable}_{$relatedPivotKey}";
                        if (in_array($constraintName, $existingConstraints)) {
                            $sql["{$pivotTable}_drop_fk2"] = "ALTER TABLE `{$pivotTable}` DROP FOREIGN KEY `{$constraintName}`;";
                        }
                        $sql["{$pivotTable}_mod2"] = "ALTER TABLE `{$pivotTable}` MODIFY COLUMN `{$relatedPivotKey}` $fk2Type NOT NULL;";
                    }
                }
            }
        }

        return $sql;
    }

    /**
     * @throws ReflectionException
     */
    public static function generatePivotConstraints(string $modelClass, PDO $db): array
    {
        $reflection = new ReflectionClass($modelClass);
        $tableAttribute = $reflection->getAttributes(Table::class)[0] ?? null;
        if (!$tableAttribute) return [];

        $currentTable = $tableAttribute->newInstance()->name;
        $sql = [];

        foreach ($reflection->getProperties() as $property) {
            $attr = $property->getAttributes(OwnedByMany::class)[0] ?? null;
            if (!$attr) continue;

            /** @var OwnedByMany $instance */
            $instance = $attr->newInstance();

            $relatedClass = $instance->related;
            $relatedTable = null;
            $relatedReflection = null;
            if (class_exists($relatedClass)) {
                $relatedReflection = new ReflectionClass($relatedClass);
                $relatedTableAttr = $relatedReflection->getAttributes(Table::class)[0] ?? null;
                if ($relatedTableAttr) {
                    $relatedTable = $relatedTableAttr->newInstance()->name;
                }
            }
            if (!$relatedTable) continue;

            // --- RESOLVE PIVOT TABLE NAME ---
            $pivotTableInput = $instance->pivotTable;
            $pivotTable = null;

            if ($pivotTableInput) {
                if (class_exists($pivotTableInput)) {
                    $pivotReflection = new ReflectionClass($pivotTableInput);
                    $pivotTableAttr = $pivotReflection->getAttributes(Table::class)[0] ?? null;
                    if ($pivotTableAttr) {
                        $pivotTable = $pivotTableAttr->newInstance()->name;
                    }
                } else {
                    $pivotTable = $pivotTableInput;
                }
            }

            if (!$pivotTable) {
                $tables = [$currentTable, $relatedTable];
                sort($tables);
                $pivotTable = implode('_', $tables);
            }

            // Fetch Constraints for this pivot table
            $existingConstraints = self::getTableConstraints($db, $pivotTable);

            // --- RESOLVE KEYS (Same logic as generatePivotTableSql) ---
            $modelPkName = self::getPrimaryKeyName($modelClass);
            $relatedPkName = self::getPrimaryKeyName($relatedClass);

            $foreignPivotKey = $instance->foreignPivotKey
                ?? (strtolower($reflection->getShortName()) . '_id');

            $relatedPivotKey = $instance->relatedPivotKey
                ?? (strtolower($relatedReflection->getShortName()) . '_id');

            $fk1Name = "fk_{$pivotTable}_{$foreignPivotKey}";
            $ownerKey1 = self::getPrimaryKeyName($modelClass) ?? 'id';
            $ownerKey2 = self::getPrimaryKeyName($relatedClass) ?? 'id';

            // SKIP IF EXISTS
            if (!in_array($fk1Name, $existingConstraints)) {
                $sql[$fk1Name] = sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE CASCADE ON UPDATE CASCADE",
                    $pivotTable, $fk1Name, $foreignPivotKey, $currentTable, $ownerKey1
                );
            }

            $fk2Name = "fk_{$pivotTable}_{$relatedPivotKey}";
            // SKIP IF EXISTS
            if (!in_array($fk2Name, $existingConstraints)) {
                $sql[$fk2Name] = sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE CASCADE ON UPDATE CASCADE",
                    $pivotTable, $fk2Name, $relatedPivotKey, $relatedTable, $ownerKey2
                );
            }
        }

        return $sql;
    }

    /**
     * @throws ReflectionException
     */
    private static function getPrimaryKeyName(string $class): ?string
    {
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(Id::class)[0] ?? null) {
                $colAttr = $property->getAttributes(Column::class)[0] ?? null;
                return $colAttr?->newInstance()->name ?? $property->getName();
            }
        }
        return null;
    }

    private static function getPrimaryKeyType(string $class, ?PDO $db = null): string
    {
        if (!class_exists($class)) return "INT";

        $reflection = new ReflectionClass($class);
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(Id::class)[0] ?? null) {
                $colAttr = $property->getAttributes(Column::class)[0] ?? null;
                if ($colAttr) {
                    /** @var Column $instance */
                    $instance = $colAttr->newInstance();
                    if ($instance->type) {
                        return self::mapFieldToSql($instance->type, $instance->length ?? 255, $db);
                    }
                }
                return "INT";
            }
        }
        return "INT";
    }

    private static function mapFieldToSql(Field $field, int $length = 255, ?PDO $db = null): string
    {
        $dialect = null;
        if ($db) {
            $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $dialect = match ($driver) {
                'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                default => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
            };
        }

        $baseType = match ($field) {
            Field::int, Field::integer => "INT",
            Field::intUnsigned, Field::integerUnsigned => "INT UNSIGNED",
            Field::tinyInteger => "TINYINT",
            Field::tinyIntegerUnsigned => "TINYINT UNSIGNED",
            Field::smallInteger => "SMALLINT",
            Field::smallIntegerUnsigned => "SMALLINT UNSIGNED",
            Field::mediumInteger => "MEDIUMINT",
            Field::mediumIntegerUnsigned => "MEDIUMINT UNSIGNED",
            Field::bigInteger => "BIGINT",
            Field::bigIntegerUnsigned => "BIGINT UNSIGNED",
            Field::decimal => "DECIMAL",
            Field::float => "FLOAT",
            Field::double => "DOUBLE",
            Field::boolean => "TINYINT(1)",
            Field::string => "VARCHAR($length)",
            Field::char => "CHAR($length)",
            Field::uuid => "CHAR(36)",
            Field::ulid => "CHAR(26)",
            Field::text => "TEXT",
            Field::mediumText => "MEDIUMTEXT",
            Field::longText => "LONGTEXT",
            Field::date => "DATE",
            Field::dateTime => "DATETIME",
            Field::time => "TIME",
            Field::timestamp => "TIMESTAMP",
            Field::year => "YEAR",
            Field::binary => "BLOB",
            Field::json => "JSON",
            Field::enum => "VARCHAR($length)", // Agnostic default for Blueprint primary keys
            default => "VARCHAR($length)"
        };

        return $dialect ? $dialect->translateType($baseType) : $baseType;
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dialect = match ($driver) {
                'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                default => throw new \Exception("Unsupported database driver: $driver"),
            };
            $result = $db->query($dialect->buildTableExistsQuery($table));
            return $result && count($result->fetchAll()) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function getTableColumns(PDO $db, string $table): array
    {
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dialect = match ($driver) {
                'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                default => throw new \Exception("Unsupported database driver: $driver"),
            };
            $stmt = $db->query($dialect->buildShowColumnsQuery($table));
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // SQLite returns 'name' and 'type', MySQL/Postgres returned 'Field' and 'Type' in our alias
                $field = $row['Field'] ?? $row['name'];
                $type = $row['Type'] ?? $row['type'];
                $columns[$field] = $type;
            }
            return $columns;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function getTableConstraints(PDO $db, string $table): array
    {
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dialect = match ($driver) {
                'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                default => throw new \Exception("Unsupported database driver: $driver"),
            };
            $stmt = $db->query($dialect->buildShowConstraintsQuery($table));
            $constraints = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // SQLite returns array of fks, we need name if present, or infer it, SQLite doesn't strictly name them.
                // For SQLite, let's just return empty string to trigger recreation if mismatch since names aren't strictly returned
                $constraints[] = $row['CONSTRAINT_NAME'] ?? '';
            }
            return $constraints;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function needsModification(string $currentType, string $targetType): bool
    {
        $cleanCurrent = strtolower($currentType);
        $cleanTarget = strtolower($targetType);

        if (str_contains($cleanTarget, 'int')) {
            $cleanCurrent = preg_replace('/\(.*?\)/', '', $cleanCurrent);
        }

        $baseCurrent = str_replace(' unsigned', '', $cleanCurrent);
        $baseTarget = str_replace(' unsigned', '', $cleanTarget);

        if ($baseCurrent !== $baseTarget) return true;

        $currentUnsigned = str_contains($cleanCurrent, 'unsigned');
        $targetUnsigned = str_contains($cleanTarget, 'unsigned');

        return $currentUnsigned !== $targetUnsigned;
    }
}
