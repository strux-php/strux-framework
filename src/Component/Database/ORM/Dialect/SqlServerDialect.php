<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Dialect;

class SqlServerDialect extends SqlDialect
{
    /**
     * Quote a value in keyword identifiers for SQL Server (brackets).
     */
    public function quote(string $value): string
    {
        if (str_contains(strtolower($value), ' as ')) {
            $parts = preg_split('/\s+as\s+/i', $value);
            return $this->quote($parts[0]) . ' AS ' . $this->quote($parts[1]);
        }

        if (str_contains($value, '.')) {
            return implode('.', array_map([$this, 'quote'], explode('.', $value)));
        }

        if ($value === '*') {
            return $value;
        }

        return '[' . str_replace(']', ']]', $value) . ']';
    }

    public function buildSelectQuery(array $query): string
    {
        $sql = "SELECT ";

        if (!empty($query['distinct'])) {
            $sql .= "DISTINCT ";
        }

        if (empty($query['selects'])) {
            $sql .= $this->quoteTable($query['from']) . ".*";
        } else {
            $selectParts = [];
            foreach ($query['selects'] as $select) {
                $selectParts[] = (string) $select['sql'];
            }
            $sql .= implode(', ', $selectParts);
        }

        $sql .= " FROM " . $this->quoteTable($query['from']);

        if (!empty($query['joins'])) {
            foreach ($query['joins'] as $join) {
                $sql .= " {$join['type']} JOIN " . $this->quoteTable($join['table']) . " ON " . $this->quote($join['first']) . " {$join['operator']} " . $this->quote($join['second']);
            }
        }

        if (!empty($query['wheres'])) {
            $sql .= " WHERE " . $this->compileWheres($query['wheres']);
        }

        if (!empty($query['groups'])) {
            $sql .= " GROUP BY " . implode(', ', array_map([$this, 'quote'], $query['groups']));
        }

        if (!empty($query['havings'])) {
            $sql .= " HAVING ";
            foreach ($query['havings'] as $i => $having) {
                if ($i > 0) {
                    $sql .= " {$having['boolean']} ";
                }
                $sql .= $this->quote($having['column']) . " {$having['operator']} ?";
            }
        }

        if (!empty($query['orders'])) {
            $orderParts = array_map(function ($o) {
                return $this->quote($o['column']) . " {$o['direction']}";
            }, $query['orders']);
            $sql .= " ORDER BY " . implode(', ', $orderParts);
        }

        if ($query['take'] !== null || $query['skip'] !== null) {
            if (empty($query['orders'])) {
                // SQL server requires an ORDER BY for OFFSET/FETCH
                $sql .= " ORDER BY (SELECT 0)";
            }

            $skip = (int) ($query['skip'] ?? 0);
            $sql .= " OFFSET {$skip} ROWS";

            if ($query['take'] !== null) {
                $take = (int) $query['take'];
                $sql .= " FETCH NEXT {$take} ROWS ONLY";
            }
        }

        return $sql;
    }

    public function buildUpsertQuery(string $table, array $columns, array $placeholders, array $uniqueBy, array $update): string
    {
        $columnsStr = implode(', ', array_map([$this, 'quote'], $columns));
        $placeholdersStr = implode(', ', $placeholders);
        $tableName = $this->quoteTable($table);
        
        $sql = "MERGE INTO {$tableName} WITH (HOLDLOCK) AS target ";
        $sql .= "USING (VALUES {$placeholdersStr}) AS source ({$columnsStr}) ";
        
        $onClauses = [];
        foreach ($uniqueBy as $col) {
            $qCol = $this->quote($col);
            $onClauses[] = "target.{$qCol} = source.{$qCol}";
        }
        $sql .= "ON " . implode(' AND ', $onClauses) . " ";
        
        if (!empty($update)) {
            $sql .= "WHEN MATCHED THEN UPDATE SET ";
            $updateClauses = [];
            foreach ($update as $key => $value) {
                if (is_int($key)) {
                    $qCol = $this->quote($value);
                    $updateClauses[] = "{$qCol} = source.{$qCol}";
                } else {
                    $updateClauses[] = $this->quote($key) . " = ?";
                }
            }
            $sql .= implode(', ', $updateClauses) . " ";
        }
        
        $sql .= "WHEN NOT MATCHED THEN INSERT ({$columnsStr}) VALUES (";
        $sourceCols = array_map(fn($c) => "source." . $this->quote($c), $columns);
        $sql .= implode(', ', $sourceCols) . ");";
        
        return $sql;
    }

    public function buildCreateTableQuery(string $table, array $columns, array $options = []): string
    {
        $columnsSql = implode(', ', $columns);
        
        // Convert MySQL AUTO_INCREMENT to SQL Server IDENTITY(1,1)
        $columnsSql = str_ireplace('AUTO_INCREMENT', 'IDENTITY(1,1)', $columnsSql);
        
        // Remove UNSIGNED
        $columnsSql = str_ireplace(' UNSIGNED', '', $columnsSql);
        
        // Convert TIMESTAMP to DATETIME2
        $columnsSql = str_ireplace('TIMESTAMP', 'DATETIME2', $columnsSql);
        
        $sql = "CREATE TABLE " . $this->quoteTable($table) . " (";
        $sql .= $columnsSql;
        $sql .= ");";

        return $sql;
    }

    public function buildTableExistsQuery(string $table): string
    {
        return "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = '{$table}'";
    }

    public function buildShowColumnsQuery(string $table): string
    {
        return "SELECT COLUMN_NAME AS Field, DATA_TYPE AS Type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{$table}'";
    }

    public function buildShowConstraintsQuery(string $table): string
    {
        return "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_NAME = '{$table}' AND CONSTRAINT_TYPE = 'FOREIGN KEY'";
    }

    public function dropAllTables(\PDO $db): void
    {
        $db->exec('EXEC sp_MSforeachtable "ALTER TABLE ? NOCHECK CONSTRAINT all"');
        $db->exec('EXEC sp_MSforeachtable "DROP TABLE ?"');
    }

    public function buildDropIndexQuery(string $table, string $indexName): string
    {
        return "DROP INDEX {$this->quote($indexName)} ON {$this->quoteTable($table)}";
    }

    public function normalizeType(string $type): string
    {
        $type = parent::normalizeType($type);

        $type = str_replace('nvarchar', 'varchar', $type);
        $type = str_replace('nchar', 'char', $type);
        $type = str_replace('datetime2', 'datetime', $type);
        $type = str_replace('bit', 'tinyint(1)', $type);
        $type = str_replace('nvarchar(max)', 'text', $type);
        $type = str_replace('varbinary(max)', 'blob', $type);
        $type = str_replace('uniqueidentifier', 'char(36)', $type);
        $type = str_replace('identity(1,1)', 'auto_increment', $type);

        return $type;
    }

    public function translateType(string $type): string
    {
        $type = strtoupper($type);

        if (str_starts_with($type, 'TINYINT(1)')) {
            return 'BIT';
        }

        if ($type === 'DATETIME' || $type === 'TIMESTAMP') {
            return 'DATETIME2';
        }

        if (str_starts_with($type, 'VARCHAR') || str_starts_with($type, 'CHAR')) {
            return str_replace(['VARCHAR', 'CHAR'], ['NVARCHAR', 'NCHAR'], $type);
        }

        if ($type === 'TEXT' || $type === 'MEDIUMTEXT' || $type === 'LONGTEXT' || $type === 'TINYTEXT') {
            return 'NVARCHAR(MAX)';
        }

        if ($type === 'BLOB') {
            return 'VARBINARY(MAX)';
        }

        if ($type === 'JSON') {
            return 'NVARCHAR(MAX)';
        }

        // Remove UNSIGNED
        $type = str_replace(' UNSIGNED', '', $type);

        return $type;
    }
}
