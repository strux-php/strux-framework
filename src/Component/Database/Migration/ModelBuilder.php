<?php

namespace Strux\Component\Database\Migration;

use PDO;
use ReflectionClass;
use ReflectionProperty;
use Strux\Component\Database\Attributes\Column;
use Strux\Component\Database\Attributes\Id;
use Strux\Component\Database\Attributes\RenamedFrom;
use Strux\Component\Database\Attributes\Table;
use Strux\Component\Database\Types\Field;

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

        $tableAttr = $reflection->getAttributes(Table::class)[0] ?? null;
        if (!$tableAttr) {
            return [];
        }
        $tableName = $tableAttr->newInstance()->name;

        if (!$this->tableExists($tableName)) {
            return $this->createTableSql($tableName, $reflection);
        } else {
            return $this->alterTableSql($tableName, $reflection);
        }
    }

    private function createTableSql(string $tableName, ReflectionClass $reflection): array
    {
        $columns = [];
        $primaryKeys = [];

        foreach ($reflection->getProperties() as $property) {
            $colAttr = $property->getAttributes(Column::class)[0] ?? null;
            $idAttr = $property->getAttributes(Id::class)[0] ?? null;

            if ($colAttr) {
                /** @var Column $instance */
                $instance = $colAttr->newInstance();
                $colName = $instance->name ?? $property->getName();

                $definition = $this->buildColumnDefinition($property, $instance, $idAttr?->newInstance());

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

        $engine = $this->dbConfig['engine'] ?: 'InnoDB';
        $charset = $this->dbConfig['charset'] ?? 'utf8mb4';
        $collation = $this->dbConfig['collation'] ?? 'utf8mb4_unicode_ci';

        return [
            "CREATE TABLE IF NOT EXISTS `$tableName` (
                $colsSql
            ) ENGINE=$engine DEFAULT CHARSET=$charset COLLATE=$collation;"
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

            $definition = $this->buildColumnDefinition($property, $colInstance, $idAttr?->newInstance());

            if (!array_key_exists($targetDbColumn, $dbColumns)) {
                $queries[] = "ALTER TABLE `$tableName` ADD COLUMN $definition;";
                $claimedDbColumns[] = strtolower($currentColName);
            } else {
                $claimedDbColumns[] = strtolower($targetDbColumn);

                if ($targetDbColumn !== $currentColName) {
                    $queries[] = "ALTER TABLE `$tableName` RENAME COLUMN `$targetDbColumn` TO `$currentColName`;";
                }

                if ($this->needsModification($dbColumns[$targetDbColumn], $property, $colInstance, (bool) $idAttr)) {
                    $queries[] = "ALTER TABLE `$tableName` MODIFY COLUMN $definition;";
                }
            }
        }

        foreach ($dbColumns as $dbColName => $details) {
            if (strtolower($dbColName) === 'id')
                continue;

            if (!in_array(strtolower($dbColName), $claimedDbColumns)) {
                $queries[] = "-- SAFETY WARNING: Potentially destructive action commented out.";
                $queries[] = "-- ALTER TABLE `$tableName` DROP COLUMN `$dbColName`;";
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

    private function buildColumnDefinition(ReflectionProperty $property, Column $columnAttr, ?Id $idAttr): string
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
        if ($isPk && $idAttr?->autoincrement && (str_contains($type, 'INT') || str_contains($type, 'int'))) {
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

    private function needsModification(array $dbDetails, ReflectionProperty $property, Column $columnAttr, bool $isPk): bool
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

        // 1. Type Check
        if (str_starts_with(strtolower($sqlType), 'enum')) {
            $cleanCurrent = str_replace(' ', '', strtolower($currentType));
            $cleanSql = str_replace(' ', '', strtolower($sqlType));
            if ($cleanCurrent !== $cleanSql)
                return true;
        } elseif (strtolower($currentType) !== strtolower($sqlType)) {
            $cleanCurrent = preg_replace('/\(.*?\)/', '', strtolower($currentType));
            $cleanSql = preg_replace('/\(.*?\)/', '', strtolower($sqlType));

            // Handle unsigned difference
            $cleanCurrent = trim(str_replace('unsigned', '', $cleanCurrent));
            $cleanSqlBase = trim(str_replace('unsigned', '', $cleanSql));

            if ($cleanCurrent !== $cleanSqlBase)
                return true;

            // Check signedness mismatch
            $dbUnsigned = str_contains(strtolower($currentType), 'unsigned');
            $sqlUnsigned = str_contains(strtolower($sqlType), 'unsigned');
            if ($dbUnsigned !== $sqlUnsigned)
                return true;

            // BUG FIX: If base types match but full types differ, lengths/precisions might have changed.
            // Integer display widths (like INT(11) vs INT) can often be ignored, 
            // but for VARCHAR, CHAR, and DECIMAL, the length/precision is critical.
            $lengthMatters = in_array($cleanCurrent, ['varchar', 'char', 'decimal', 'float', 'double']);
            if ($lengthMatters) {
                // Ensure spaces are stripped for a fair comparison like "decimal(10, 2)" vs "decimal(10,2)"
                $normalizedCurrent = str_replace(' ', '', strtolower($currentType));
                $normalizedSql = str_replace(' ', '', strtolower($sqlType));
                if ($normalizedCurrent !== $normalizedSql)
                    return true;
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
            $stmt = $this->db->query("DESCRIBE `$tableName`");
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[$row['Field']] = [
                    'type' => $row['Type'],
                    'nullable' => $row['Null'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra'] ?? '',
                ];
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
            $result = $this->db->query("SHOW TABLES LIKE '$table'");
            return !empty($result->fetchAll());
        } catch (\Exception $e) {
            return false;
        }
    }

    private function mapType(?Field $field, ?string $phpType, int $length = 255, ?array $enums = null, int $precision = 10, int $scale = 2): string
    {
        if ($enums !== null || $field === Field::enum) {
            if ($enums) {
                $options = array_map(fn($val) => "'$val'", $enums);
                $enumStr = implode(", ", $options);
                return "ENUM($enumStr)";
            }
        }

        if ($field === null) {
            return match ($phpType) {
                'int' => "INT",
                'float' => "FLOAT",
                'bool' => "TINYINT(1)",
                'string' => "VARCHAR($length)",
                'DateTime', '\DateTime', 'DateTimeInterface' => "DATETIME",
                'array' => "JSON",
                default => "VARCHAR($length)"
            };
        }

        return match ($field) {
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
    }
}
