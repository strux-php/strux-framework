<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Dialect;

class SqliteDialect extends SqlDialect
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
                    $updateClauses[] = "{$qCol} = excluded.{$qCol}";
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
        
        // Convert MySQL AUTO_INCREMENT to SQLite AUTOINCREMENT
        $columnsSql = str_ireplace('AUTO_INCREMENT', 'AUTOINCREMENT', $columnsSql);
        
        // Remove UNSIGNED
        $columnsSql = str_ireplace(' UNSIGNED', '', $columnsSql);
        
        // Remove COLLATE since SQLite doesn't strictly support the mysql collations in definitions this way easily without extensions
        
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->quoteTable($table) . " (";
        $sql .= $columnsSql;
        $sql .= ");";

        return $sql;
    }

    public function buildTableExistsQuery(string $table): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'";
    }

    public function buildShowColumnsQuery(string $table): string
    {
        return "PRAGMA table_info(" . $this->quoteTable($table) . ")";
    }

    public function buildShowConstraintsQuery(string $table): string
    {
        return "PRAGMA foreign_key_list(" . $this->quoteTable($table) . ")";
    }

    public function dropAllTables(\PDO $db): void
    {
        $db->exec('PRAGMA foreign_keys = OFF;');
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $db->exec("DROP TABLE " . $this->quoteTable($table));
            echo "Dropped table: $table\n";
        }
        $db->exec('PRAGMA foreign_keys = ON;');
    }
}
