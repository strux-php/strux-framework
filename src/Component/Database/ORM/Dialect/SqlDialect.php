<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Dialect;

abstract class SqlDialect
{
    /**
     * Quote a table in keyword identifiers.
     */
    public function quoteTable(string $table): string
    {
        return $this->quote($table);
    }

    /**
     * Quote a value in keyword identifiers.
     */
    public function quote(string $value): string
    {
        if (str_contains($value, ' as ')) {
            $parts = explode(' as ', $value);
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

        if ($query['take'] !== null) {
            $sql .= " LIMIT " . (int) $query['take'];
        }

        if ($query['skip'] !== null) {
            $sql .= " OFFSET " . (int) $query['skip'];
        }

        return $sql;
    }

    protected function compileWheres(array $wheres): string
    {
        $sql = '';
        foreach ($wheres as $i => $where) {
            if ($i > 0) {
                $boolean = strtoupper($where['boolean']);
                $sql .= " $boolean ";
            } elseif ($where['boolean'] === 'AND NOT' || $where['boolean'] === 'OR NOT') {
                $sql .= 'NOT ';
            }

            if ($where['type'] === 'nested') {
                $nestedSql = $this->compileWheres($where['query']['wheres'] ?? []);
                if ($nestedSql) {
                    $sql .= "($nestedSql)";
                }
            } elseif ($where['type'] === 'raw') {
                $sql .= $where['sql'];
            } elseif ($where['type'] === 'in' || $where['type'] === 'not_in') {
                $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                $operator = ($where['type'] === 'in') ? 'IN' : 'NOT IN';
                $sql .= $this->quote($where['column']) . " {$operator} ({$placeholders})";
            } elseif ($where['type'] === 'basic') {
                $sql .= $this->quote($where['column']) . " {$where['operator']} ?";
            }
        }
        return $sql;
    }

    public function buildUpdateQuery(string $table, array $columns, array $wheres = []): string
    {
        $table = $this->quoteTable($table);
        $setSql = implode(', ', array_map(fn($col) => $this->quote($col) . ' = ?', $columns));
        $whereSql = empty($wheres) ? '' : ' WHERE ' . $this->compileWheres($wheres);

        return "UPDATE {$table} SET {$setSql}{$whereSql}";
    }

    public function buildInsertQuery(string $table, array $columns, array $values): string
    {
        $columnsStr = implode(', ', array_map([$this, 'quote'], $columns));
        $placeholdersStr = implode(', ', $values);
        return "INSERT INTO " . $this->quoteTable($table) . " ($columnsStr) VALUES ($placeholdersStr)";
    }



    public function buildDeleteQuery(string $table): string
    {
        return "DELETE FROM " . $this->quoteTable($table);
    }

    abstract public function buildUpsertQuery(string $table, array $columns, array $placeholders, array $uniqueBy, array $update): string;

    // --- Schema / DDL Methods ---

    /**
     * Build a CREATE TABLE query.
     * @param string $table The table name
     * @param array $columns An array of column definitions (e.g. ['id INT AUTO_INCREMENT PRIMARY KEY', ...])
     * @param array $options Dialect specific options like engine, charset, etc.
     */
    abstract public function buildCreateTableQuery(string $table, array $columns, array $options = []): string;

    /**
     * Build a query to check if a table exists.
     */
    abstract public function buildTableExistsQuery(string $table): string;

    /**
     * Build a query to show columns for a table.
     */
    abstract public function buildShowColumnsQuery(string $table): string;

    /**
     * Build a query to show constraints for a table.
     */
    abstract public function buildShowConstraintsQuery(string $table): string;

    /**
     * Build a query to show indexes for a table.
     */
    public function buildShowIndexesQuery(string $table): string
    {
        return "SHOW INDEX FROM " . $this->quoteTable($table);
    }

    /**
     * Drop all tables in the database.
     */
    abstract public function dropAllTables(\PDO $db): void;

    /**
     * Build an ADD INDEX query.
     */
    public function buildAddIndexQuery(string $table, string $indexName, array $columns, bool $isUnique = false): string
    {
        $uniqueStr = $isUnique ? 'UNIQUE ' : '';
        $cols = implode(', ', array_map([$this, 'quote'], $columns));
        return "CREATE {$uniqueStr}INDEX {$this->quote($indexName)} ON {$this->quoteTable($table)} ($cols)";
    }

    /**
     * Build a DROP INDEX query.
     */
    abstract public function buildDropIndexQuery(string $table, string $indexName): string;

    public function buildAddColumnQuery(string $table, string $definition): string
    {
        return "ALTER TABLE " . $this->quoteTable($table) . " ADD COLUMN {$definition};";
    }

    public function buildRenameColumnQuery(string $table, string $oldName, string $newName): string
    {
        return "ALTER TABLE " . $this->quoteTable($table) . " RENAME COLUMN " . $this->quote($oldName) . " TO " . $this->quote($newName) . ";";
    }

    public function buildModifyColumnQuery(string $table, string $definition): string
    {
        return "ALTER TABLE " . $this->quoteTable($table) . " MODIFY COLUMN {$definition};";
    }

    public function buildDropColumnQuery(string $table, string $column): string
    {
        return "ALTER TABLE " . $this->quoteTable($table) . " DROP COLUMN " . $this->quote($column);
    }

    public function buildDropForeignKeyQuery(string $table, string $constraint): string
    {
        return "ALTER TABLE " . $this->quoteTable($table) . " DROP FOREIGN KEY " . $this->quote($constraint);
    }

    /**
     * Translate generic framework types to dialect-specific types.
     */
    public function translateType(string $type): string
    {
        return $type; // Default (MySQL-compatible)
    }
}
