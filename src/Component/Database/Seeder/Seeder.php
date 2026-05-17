<?php

declare(strict_types=1);

namespace Strux\Component\Database\Seeder;

use PDO;

abstract class Seeder implements SeederInterface
{
    protected ?PDO $db = null;

    public function run(?PDO $db = null): void
    {
        if ($db !== null) {
            $this->db = $db;
        }
        $this->seed();
    }

    abstract protected function seed(): void;

    protected function insert(string $table, array $data): void
    {
        $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
    }

    protected function upsert(string $table, array $data, string $uniqueKey = 'id'): void
    {
        $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $updates = implode(', ', array_map(fn($col) => "`$col` = VALUES(`$col`)", array_keys($data)));
        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
    }
}
