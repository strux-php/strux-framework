<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Dialect;

class PostgresDialect extends SqlDialect
{
    public function buildUpsertQuery(string $table, array $columns, array $placeholders, array $uniqueBy, array $update): string
    {
        $columnsStr = implode(', ', array_map([$this, 'quote'], $columns));
        $placeholdersStr = implode(', ', $placeholders);
        $tableName = $this->quoteTable($table);
        $sql = "INSERT INTO {$tableName} ({$columnsStr}) VALUES {$placeholdersStr}";

        if (!empty($update)) {
            $uniqueCols = implode(', ', array_map([$this, 'quote'], $uniqueBy));
            $sql .= " ON CONFLICT ({$uniqueCols}) DO UPDATE SET ";
            
            $updateClauses = [];
            foreach ($update as $key => $value) {
                if (is_int($key)) {
                    $qCol = $this->quote($value);
                    $updateClauses[] = "{$qCol} = EXCLUDED.{$qCol}";
                } else {
                    $updateClauses[] = $this->quote($key) . " = ?";
                }
            }
            $sql .= implode(', ', $updateClauses);
        }

        return $sql;
    }

    public function buildCreateTableQuery(string $table, array $columns, array $options = []): string
    {
        $columnsSql = implode(', ', $columns);
        
        // Convert some typical MySQL definitions to Postgres
        // e.g. AUTO_INCREMENT -> SERIAL
        $columnsSql = str_ireplace('AUTO_INCREMENT', 'SERIAL', $columnsSql);
        // e.g. INT SERIAL -> SERIAL
        $columnsSql = str_ireplace('INT SERIAL', 'SERIAL', $columnsSql);
        // LONGTEXT -> TEXT
        $columnsSql = str_ireplace('LONGTEXT', 'TEXT', $columnsSql);
        // Remove UNSIGNED
        $columnsSql = str_ireplace(' UNSIGNED', '', $columnsSql);
        
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->quoteTable($table) . " (";
        $sql .= $columnsSql;
        $sql .= ");";

        return $sql;
    }

    public function buildTableExistsQuery(string $table): string
    {
        return "SELECT tablename FROM pg_catalog.pg_tables WHERE tablename = '{$table}'";
    }

    public function buildShowColumnsQuery(string $table): string
    {
        return "SELECT column_name AS Field, data_type AS Type FROM information_schema.columns WHERE table_name = '{$table}'";
    }

    public function buildShowConstraintsQuery(string $table): string
    {
        return "SELECT tc.constraint_name AS CONSTRAINT_NAME
                FROM information_schema.table_constraints tc
                WHERE tc.table_name = '{$table}' AND tc.constraint_type = 'FOREIGN KEY'";
    }

    public function buildShowIndexesQuery(string $table): string
    {
        return "SELECT indexname AS \"Key_name\" FROM pg_indexes WHERE tablename = '{$table}'";
    }

    public function dropAllTables(\PDO $db): void
    {
        $db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        $db->exec('GRANT ALL ON SCHEMA public TO public;');
        echo "Dropped all tables by recreating public schema.\n";
    }

    public function buildDropIndexQuery(string $table, string $indexName): string
    {
        return "DROP INDEX {$this->quote($indexName)}";
    }

    public function buildModifyColumnQuery(string $table, string $definition): string
    {
        // Postgres uses ALTER TABLE table_name ALTER COLUMN column_name TYPE new_type
        // which requires complex parsing. Returning a comment to prevent syntax errors.
        return "-- ALTER TABLE " . $this->quoteTable($table) . " MODIFY COLUMN {$definition} (Skipped by Postgres dialect)";
    }

    public function buildDropForeignKeyQuery(string $table, string $constraint): string
    {
        return "ALTER TABLE " . $this->quoteTable($table) . " DROP CONSTRAINT " . $this->quote($constraint);
    }

    public function normalizeType(string $type): string
    {
        $type = parent::normalizeType($type);

        $type = str_replace('character varying', 'varchar', $type);
        $type = str_replace('timestamp without time zone', 'timestamp', $type);
        $type = str_replace('timestamp with time zone', 'timestamptz', $type);
        $type = str_replace('integer', 'int', $type);
        $type = str_replace('boolean', 'tinyint(1)', $type);
        $type = str_replace('double precision', 'double', $type);
        $type = str_replace('bytea', 'blob', $type);
        $type = str_replace('real', 'float', $type);
        $type = str_replace('numeric', 'decimal', $type);

        return $type;
    }

    public function translateType(string $type): string
    {
        $type = strtoupper($type);

        // Map Booleans
        if (str_starts_with($type, 'TINYINT(1)')) {
            return 'BOOLEAN';
        }

        // Map Integers
        if ($type === 'TINYINT' || $type === 'SMALLINT') {
            return 'SMALLINT';
        }
        if ($type === 'MEDIUMINT' || $type === 'INT' || $type === 'INTEGER') {
            return 'INTEGER';
        }

        // Map Decimals
        if ($type === 'DOUBLE') {
            return 'DOUBLE PRECISION';
        }

        // Map Texts
        if ($type === 'MEDIUMTEXT' || $type === 'LONGTEXT' || $type === 'TINYTEXT') {
            return 'TEXT';
        }

        // Map Dates
        if ($type === 'DATETIME') {
            return 'TIMESTAMP';
        }

        // Map Blobs
        if ($type === 'BLOB') {
            return 'BYTEA';
        }

        // Remove UNSIGNED as Postgres doesn't support it
        $type = str_replace(' UNSIGNED', '', $type);

        return $type;
    }
}
