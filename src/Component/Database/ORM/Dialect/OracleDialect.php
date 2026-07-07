<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Dialect;

class OracleDialect extends SqlDialect
{
    /**
     * Quote a value in keyword identifiers for Oracle (double quotes).
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

        return '"' . str_replace('"', '""', $value) . '"';
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
            $skip = (int) ($query['skip'] ?? 0);
            $sql .= " OFFSET {$skip} ROWS";

            if ($query['take'] !== null) {
                $take = (int) $query['take'];
                $sql .= " FETCH NEXT {$take} ROWS ONLY";
            }
        }

        return $sql;
    }

    public function wrapJsonPath(string $column, string $path): string
    {
        return sprintf("JSON_VALUE(%s, '%s')", $this->quote($column), $path);
    }

    public function buildUpsertQuery(string $table, array $columns, array $placeholders, array $uniqueBy, array $update): string
    {
        $columnsStr = implode(', ', array_map([$this, 'quote'], $columns));
        $tableName = $this->quoteTable($table);
        
        // Oracle requires a SELECT ... FROM DUAL to build the source for MERGE INTO
        $sourceSelects = [];
        foreach ($columns as $index => $col) {
            $sourceSelects[] = "{$placeholders[$index]} AS " . $this->quote($col);
        }
        $sourceStr = "SELECT " . implode(', ', $sourceSelects) . " FROM DUAL";

        $sql = "MERGE INTO {$tableName} target ";
        $sql .= "USING ({$sourceStr}) source ";
        
        $onClauses = [];
        foreach ($uniqueBy as $col) {
            $qCol = $this->quote($col);
            $onClauses[] = "target.{$qCol} = source.{$qCol}";
        }
        $sql .= "ON (" . implode(' AND ', $onClauses) . ") ";
        
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
        $sql .= implode(', ', $sourceCols) . ")";
        
        return $sql;
    }

    public function buildCreateTableQuery(string $table, array $columns, array $options = []): string
    {
        $columnsSql = implode(', ', $columns);
        
        // Convert AUTO_INCREMENT to Oracle 12c+ Identity
        $columnsSql = str_ireplace('AUTO_INCREMENT', 'GENERATED BY DEFAULT ON NULL AS IDENTITY', $columnsSql);
        
        // Remove UNSIGNED
        $columnsSql = str_ireplace(' UNSIGNED', '', $columnsSql);
        
        $sql = "CREATE TABLE " . $this->quoteTable($table) . " (";
        $sql .= $columnsSql;
        $sql .= ")";

        return $sql;
    }

    public function buildTableExistsQuery(string $table): string
    {
        // Table names are typically uppercase in USER_TABLES unless explicitly quoted lowercase during creation
        return "SELECT TABLE_NAME FROM USER_TABLES WHERE TABLE_NAME = UPPER('{$table}') OR TABLE_NAME = '{$table}'";
    }

    public function buildShowColumnsQuery(string $table): string
    {
        return "SELECT COLUMN_NAME AS Field, DATA_TYPE AS Type FROM USER_TAB_COLUMNS WHERE TABLE_NAME = UPPER('{$table}') OR TABLE_NAME = '{$table}'";
    }

    public function buildShowConstraintsQuery(string $table): string
    {
        return "SELECT CONSTRAINT_NAME FROM USER_CONSTRAINTS WHERE CONSTRAINT_TYPE = 'R' AND (TABLE_NAME = UPPER('{$table}') OR TABLE_NAME = '{$table}')";
    }

    public function dropAllTables(\PDO $db): void
    {
        $sql = "
            BEGIN
                FOR t IN (SELECT table_name FROM user_tables) LOOP
                    EXECUTE IMMEDIATE 'DROP TABLE \"' || t.table_name || '\" CASCADE CONSTRAINTS';
                END LOOP;
            END;
        ";
        $db->exec($sql);
    }

    public function buildDropIndexQuery(string $table, string $indexName): string
    {
        return "DROP INDEX {$this->quote($indexName)}";
    }

    public function normalizeType(string $type): string
    {
        $type = parent::normalizeType($type);

        $type = str_replace('varchar2', 'varchar', $type);
        $type = str_replace('number(1)', 'tinyint(1)', $type);
        $type = str_replace('number(20)', 'bigint', $type);
        $type = str_replace('number', 'decimal', $type);
        $type = str_replace('clob', 'text', $type);
        $type = str_replace('blob', 'blob', $type);
        $type = str_replace('timestamp', 'datetime', $type);
        $type = str_replace('raw', 'binary', $type);
        $type = str_replace('float', 'float', $type);

        return $type;
    }

    public function translateType(string $type): string
    {
        $type = strtoupper($type);

        if ($type === 'TINYINT(1)' || $type === 'BOOLEAN') {
            return 'NUMBER(1)';
        }

        if (str_starts_with($type, 'INT') || str_starts_with($type, 'INTEGER')) {
            return 'NUMBER(10)';
        }
        
        if (str_starts_with($type, 'BIGINT')) {
            return 'NUMBER(20)';
        }

        if ($type === 'DATETIME' || $type === 'TIMESTAMP') {
            return 'TIMESTAMP';
        }

        if (str_starts_with($type, 'VARCHAR') || str_starts_with($type, 'CHAR')) {
            return str_replace(['VARCHAR', 'CHAR'], ['VARCHAR2', 'CHAR'], $type);
        }

        if ($type === 'TEXT' || $type === 'MEDIUMTEXT' || $type === 'LONGTEXT' || $type === 'TINYTEXT' || $type === 'JSON') {
            return 'CLOB';
        }

        if ($type === 'BLOB') {
            return 'BLOB';
        }

        // Remove UNSIGNED
        $type = str_replace(' UNSIGNED', '', $type);

        return $type;
    }
}
