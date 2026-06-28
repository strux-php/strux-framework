<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Dialect;

class MariaDbDialect extends MySqlDialect
{
    public function normalizeType(string $type): string
    {
        $type = parent::normalizeType($type);
        return $type;
    }

    public function buildShowIndexesQuery(string $table): string
    {
        return "SHOW INDEX FROM " . $this->quoteTable($table);
    }

    public function buildRenameColumnQuery(string $table, string $oldName, string $newName): string
    {
        return "ALTER TABLE " . $this->quoteTable($table) . " RENAME COLUMN " . $this->quote($oldName) . " TO " . $this->quote($newName) . ";";
    }
}
