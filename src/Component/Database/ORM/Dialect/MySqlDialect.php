<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Dialect;

class MySqlDialect extends SqlDialect
{
    /**
     * Quote a value in keyword identifiers for MySQL (backticks).
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

        return '`' . str_replace('`', '``', $value) . '`';
    }

    public function buildUpsertQuery(string $table, array $columns, array $placeholders, array $uniqueBy, array $update): string
    {
        $columnsStr = implode(', ', array_map([$this, 'quote'], $columns));
        $placeholdersStr = implode(', ', $placeholders);
        $tableName = $this->quoteTable($table);
        $sql = "INSERT INTO {$tableName} ({$columnsStr}) VALUES {$placeholdersStr}";

        if (!empty($update)) {
            $updateClauses = [];
            foreach ($update as $key => $value) {
                if (is_int($key)) {
                    $qCol = $this->quote($value);
                    $updateClauses[] = "{$qCol} = VALUES({$qCol})";
                } else {
                    $updateClauses[] = $this->quote($key) . " = ?";
                }
            }
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateClauses);
        }

        return $sql;
    }

    public function buildCreateTableQuery(string $table, array $columns, array $options = []): string
    {
        $engine = $options['engine'] ?? 'InnoDB';
        $charset = $options['charset'] ?? 'utf8mb4';
        $collation = $options['collation'] ?? 'utf8mb4_unicode_ci';

        $columnsSql = implode(', ', $columns);
        
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->quoteTable($table) . " (";
        $sql .= $columnsSql;
        $sql .= ") ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collation};";

        return $sql;
    }

    public function buildTableExistsQuery(string $table): string
    {
        return "SHOW TABLES LIKE '{$table}'";
    }

    public function buildShowColumnsQuery(string $table): string
    {
        return "DESCRIBE " . $this->quoteTable($table);
    }

    public function buildShowConstraintsQuery(string $table): string
    {
        return "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' 
                AND REFERENCED_TABLE_NAME IS NOT NULL";
    }

    public function dropAllTables(\PDO $db): void
    {
        try {
            $db->exec('SET FOREIGN_KEY_CHECKS=0;');
            $stmt = $db->query('SHOW TABLES');
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $db->exec("DROP TABLE IF EXISTS " . $this->quoteTable($table));
                echo "Dropped table: $table\n";
            }

            $db->exec('SET FOREIGN_KEY_CHECKS=1;');
        } catch (\Exception $e) {
            $db->exec('SET FOREIGN_KEY_CHECKS=1;');
            throw $e;
        }
    }

    public function buildDropIndexQuery(string $table, string $indexName): string
    {
        return "DROP INDEX " . $this->quote($indexName) . " ON " . $this->quoteTable($table);
    }

    public function normalizeType(string $type): string
    {
        $type = parent::normalizeType($type);

        $type = str_replace('tinyint(1)', 'boolean', $type);

        return $type;
    }
}
