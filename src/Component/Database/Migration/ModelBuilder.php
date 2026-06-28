<?php

namespace Strux\Component\Database\Migration;

use PDO;
use ReflectionClass;
use ReflectionProperty;
use Strux\Component\Database\Schema\Attributes\Column;
use Strux\Component\Database\Schema\Attributes\Id;
use Strux\Component\Database\Schema\Attributes\RenamedFrom;
use Strux\Component\Database\Schema\Attributes\Entity;
use Strux\Component\Database\Schema\Types\Field;

class ModelBuilder
{
    private string $modelClass;
    private ?PDO $db;
    private array $dbConfig;

    public function __construct(string $modelClass, ?PDO $db, array $dbConfig = [])
    {
        $this->modelClass = $modelClass;
        $this->db = $db;
        $this->dbConfig = $dbConfig;
    }

    /**
     * @throws \ReflectionException
     */
    public function generateSql(): array
    {
        $reflection = new ReflectionClass($this->modelClass);

        $entityAttr = $reflection->getAttributes(Entity::class)[0] ?? null;
        if (!$entityAttr || $entityAttr->newInstance()->table === null) {
            return [];
        }
        $tableName = $entityAttr->newInstance()->table;

        if (!$this->tableExists($tableName)) {
            return $this->createTableSql($tableName, $reflection);
        } else {
            return $this->alterTableSql($tableName, $reflection);
        }
    }

    private function getDialect(): \Strux\Component\Database\ORM\Dialect\SqlDialect
    {
        $driver = $this->db ? $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) : 'mysql';
        return match ($driver) {
            'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
            'mariadb' => new \Strux\Component\Database\ORM\Dialect\MariaDbDialect(),
            'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
            'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
            'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
            default => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
        };
    }

    private function createTableSql(string $tableName, ReflectionClass $reflection): array
    {
        $columns = [];
        $primaryKeys = [];
        $pkCount = 0;
        $autoincrementAssigned = false;

        foreach ($reflection->getProperties() as $property) {
            $idAttr = $property->getAttributes(Id::class)[0] ?? null;
            if ($idAttr) {
                $pkCount++;
            }
        }

        foreach ($reflection->getProperties() as $property) {
            $colAttr = $property->getAttributes(Column::class)[0] ?? null;
            $idAttr = $property->getAttributes(Id::class)[0] ?? null;

            if ($colAttr) {
                /** @var Column $instance */
                $instance = $colAttr->newInstance();
                $colName = $instance->name ?? $property->getName();

                $isFirstAutoIncrement = $idAttr !== null && !$autoincrementAssigned && $idAttr->newInstance()->autoincrement;
                $definition = $this->buildColumnDefinition($property, $instance, $idAttr?->newInstance(), $isFirstAutoIncrement);
                if ($isFirstAutoIncrement) {
                    $autoincrementAssigned = true;
                }

                if ($idAttr) {
                    $primaryKeys[] = "`$colName`";
                }

                $columns[] = $definition;
            }
        }

        if (empty($columns)) {
            return [];
        }

        if (!empty($primaryKeys)) {
            $columns[] = "PRIMARY KEY (" . implode(', ', $primaryKeys) . ")";
        }

        $colsSql = implode(",\n\t\t\t\t", $columns);

        $engine = $this->dbConfig['engine'] ?? 'InnoDB';
        $charset = $this->dbConfig['charset'] ?? 'utf8mb4';
        $collation = $this->dbConfig['collation'] ?? 'utf8mb4_unicode_ci';

        $driver = $this->db ? $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) : 'mysql';
        $dialect = match ($driver) {
            'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
            'mariadb' => new \Strux\Component\Database\ORM\Dialect\MariaDbDialect(),
            'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
            'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
            'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
            default => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
        };

        return [
            $dialect->buildCreateTableQuery($tableName, $columns, [
                'engine' => $engine,
                'charset' => $charset,
                'collation' => $collation
            ])
        ];
    }

    private function alterTableSql(string $tableName, ReflectionClass $reflection): array
    {
        $dbColumns = $this->getSchemaDetails($tableName);
        $lowerDbColumns = [];
        foreach ($dbColumns as $key => $val) {
            $lowerDbColumns[strtolower($key)] = $key;
        }

        $queries = [];
        $claimedDbColumns = [];
        $autoincrementAssigned = false;

        foreach ($reflection->getProperties() as $property) {
            $colAttr = $property->getAttributes(Column::class)[0] ?? null;
            if (!$colAttr)
                continue;

            $idAttr = $property->getAttributes(Id::class)[0] ?? null;
            $colInstance = $colAttr->newInstance();
            $currentColName = $colInstance->name ?? $property->getName();

            $renameAttr = $property->getAttributes(RenamedFrom::class)[0] ?? null;
            $targetDbColumn = $currentColName;

            $lowerTarget = strtolower($targetDbColumn);
            if (isset($lowerDbColumns[$lowerTarget])) {
                $targetDbColumn = $lowerDbColumns[$lowerTarget];
            }

            if ($renameAttr) {
                $oldName = $renameAttr->newInstance()->oldName;
                if (isset($lowerDbColumns[strtolower($oldName)])) {
                    $targetDbColumn = $lowerDbColumns[strtolower($oldName)];
                }
            }

            $isFirstAutoIncrement = $idAttr !== null && !$autoincrementAssigned && $idAttr->newInstance()->autoincrement;
            $definition = $this->buildColumnDefinition($property, $colInstance, $idAttr?->newInstance(), $isFirstAutoIncrement);
            if ($isFirstAutoIncrement) {
                $autoincrementAssigned = true;
            }

            if (!array_key_exists($targetDbColumn, $dbColumns)) {
                $queries[] = $this->getDialect()->buildAddColumnQuery($tableName, $definition);
                $claimedDbColumns[] = strtolower($currentColName);
            } else {
                $claimedDbColumns[] = strtolower($targetDbColumn);

                if ($targetDbColumn !== $currentColName) {
                    $queries[] = $this->getDialect()->buildRenameColumnQuery($tableName, $targetDbColumn, $currentColName);
                }

                if ($this->needsModification($dbColumns[$targetDbColumn], $property, $colInstance, (bool) $idAttr, $this->getDialect())) {
                    $queries[] = $this->getDialect()->buildModifyColumnQuery($tableName, $definition);
                }
            }
        }

        foreach ($dbColumns as $dbColName => $details) {
            if (strtolower($dbColName) === 'id')
                continue;

            if (!in_array(strtolower($dbColName), $claimedDbColumns)) {
                $queries[] = "-- SAFETY WARNING: Potentially destructive action commented out.";
                $queries[] = "-- " . $this->getDialect()->buildDropColumnQuery($tableName, $dbColName);
            }
        }

        return $queries;
    }

    private function getPhpTypeName(ReflectionProperty $property): string
    {
        $type = $property->getType();
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }
        if ($type instanceof \ReflectionUnionType) {
            $firstType = $type->getTypes()[0];
            return $firstType instanceof \ReflectionNamedType ? $firstType->getName() : (string) $firstType;
        }
        return (string) $type;
    }

    private function buildColumnDefinition(ReflectionProperty $property, Column $columnAttr, ?Id $idAttr, bool $isFirstAutoIncrementPk = true): string
    {
        $colName = $columnAttr->name ?? $property->getName();
        $type = $this->mapType(
            $columnAttr->type,
            $this->getPhpTypeName($property),
            $columnAttr->length,
            $columnAttr->enums,
            $columnAttr->precision,
            $columnAttr->scale
        );

        $definition = "`$colName` $type";

        $isPk = $idAttr !== null;
        if ($isPk && $idAttr?->autoincrement && $isFirstAutoIncrementPk && (str_contains($type, 'INT') || str_contains($type, 'int'))) {
            $definition .= " AUTO_INCREMENT";
        }

        $isNullable = ($columnAttr->nullable || $property->getType()?->allowsNull()) && !$isPk;
        $definition .= $isNullable ? " NULL" : " NOT NULL";

        $default = $columnAttr->default;
        if ($default === null && $property->hasDefaultValue()) {
            $default = $property->getDefaultValue();
        }

        if ($default !== null && !$isPk) {
            $sqlDefault = $this->formatDefaultValue($default);
            $definition .= " DEFAULT $sqlDefault";
        } elseif ($columnAttr->currentTimestamp) {
            $definition .= " DEFAULT CURRENT_TIMESTAMP";
        }

        if ($columnAttr->onUpdateCurrentTimestamp) {
            $definition .= " ON UPDATE CURRENT_TIMESTAMP";
        }

        return $definition;
    }

    private function formatDefaultValue(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_null($value)) {
            return 'NULL';
        }
        return (string) $value;
    }

    private function needsModification(array $dbDetails, ReflectionProperty $property, Column $columnAttr, bool $isPk, \Strux\Component\Database\ORM\Dialect\SqlDialect $dialect): bool
    {
        $phpType = $this->getPhpTypeName($property);

        $sqlType = $this->mapType(
            $columnAttr->type,
            $phpType,
            $columnAttr->length,
            $columnAttr->enums,
            $columnAttr->precision,
            $columnAttr->scale
        );

        $isNullable = ($columnAttr->nullable || $property->getType()?->allowsNull()) && !$isPk;

        $currentType = $dbDetails['type'];
        $currentNull = $dbDetails['nullable'];

        // 1. Type Check using dialect-normalized types
        $normalizedCurrent = $dialect->normalizeType($currentType);
        $normalizedSql = $dialect->normalizeType($sqlType);

        if ($normalizedCurrent !== $normalizedSql) {
            // For types where length/precision matters, do a full comparison
            $baseCurrent = preg_replace('/\(.*?\)/', '', $normalizedCurrent);
            $baseSql = preg_replace('/\(.*?\)/', '', $normalizedSql);

            if ($baseCurrent !== $baseSql) {
                return true;
            }

            // Same base type — check signedness mismatch
            $dbUnsigned = str_contains(strtolower($currentType), 'unsigned');
            $sqlUnsigned = str_contains(strtolower($sqlType), 'unsigned');
            if ($dbUnsigned !== $sqlUnsigned) {
                return true;
            }

            // For varchar, char, decimal, float, double — length/precision differences matter
            $lengthMatters = in_array($baseCurrent, ['varchar', 'char', 'decimal', 'float', 'double']);
            if ($lengthMatters) {
                $strippedCurrent = str_replace(' ', '', strtolower($currentType));
                $strippedSql = str_replace(' ', '', strtolower($sqlType));
                if ($strippedCurrent !== $strippedSql) {
                    return true;
                }
            }
        }

        // 2. Nullable Check
        $dbIsNullable = ($currentNull === 'YES');
        if ($dbIsNullable !== $isNullable)
            return true;

        // 3. Timestamp Checks
        if ($columnAttr->onUpdateCurrentTimestamp) {
            if (!str_contains(strtolower($dbDetails['extra'] ?? ''), 'on update current_timestamp')) {
                return true;
            }
        }

        return false;
    }

    private function getSchemaDetails(string $tableName): array
    {
        if (!$this->db)
            return [];

        try {
            $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dialect = match ($driver) {
                'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                'mariadb' => new \Strux\Component\Database\ORM\Dialect\MariaDbDialect(),
                'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                default => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
            };

            $stmt = $this->db->query($dialect->buildShowColumnsQuery($tableName));
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $field = $row['Field'] ?? $row['field'] ?? $row['name'] ?? null;
                $type = $row['Type'] ?? $row['type'] ?? null;
                $nullable = $row['Null'] ?? ((isset($row['notnull']) && $row['notnull']) ? 'NO' : 'YES');
                $default = $row['Default'] ?? $row['dflt_value'] ?? null;

                if ($field !== null) {
                    $columns[$field] = [
                        'type' => $type,
                        'nullable' => $nullable,
                        'default' => $default,
                        'extra' => $row['Extra'] ?? '',
                    ];
                }
            }
            return $columns;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function tableExists(string $table): bool
    {
        if (!$this->db)
            return false;

        try {
            $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dialect = match ($driver) {
                'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                'mariadb' => new \Strux\Component\Database\ORM\Dialect\MariaDbDialect(),
                'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                default => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
            };

            $result = $this->db->query($dialect->buildTableExistsQuery($table));
            return $result && count($result->fetchAll()) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function mapType(?Field $field, ?string $phpType, int $length = 255, ?array $enums = null, int $precision = 10, int $scale = 2): string
    {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $dialect = match ($driver) {
            'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
            'mariadb' => new \Strux\Component\Database\ORM\Dialect\MariaDbDialect(),
            'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
            'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
            'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
            default => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
        };

        if ($enums !== null || $field === Field::enum) {
            if ($driver === 'mysql') {
                if ($enums) {
                    $options = array_map(fn($val) => "'$val'", $enums);
                    $enumStr = implode(", ", $options);
                    return "ENUM($enumStr)";
                }
            } else {
                return "VARCHAR($length)";
            }
        }

        if ($field === null) {
            $baseType = match ($phpType) {
                'int' => "INT",
                'float' => "FLOAT",
                'bool' => "TINYINT(1)",
                'string' => "VARCHAR($length)",
                'DateTime', '\DateTime', 'DateTimeInterface' => "DATETIME",
                'array' => "JSON",
                default => "VARCHAR($length)"
            };
            return $dialect->translateType($baseType);
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
            Field::decimal => "DECIMAL($precision,$scale)",
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
            default => "VARCHAR($length)"
        };

        return $dialect->translateType($baseType);
    }
}
