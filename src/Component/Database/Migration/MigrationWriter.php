<?php

namespace Strux\Component\Database\Migration;

class MigrationWriter
{
    private string $migrationPath;
    private ?\PDO $db;

    public function __construct(string $basePath, ?\PDO $db = null)
    {
        $this->db = $db;
        $this->migrationPath = \Strux\Component\Config\DirectoryResolver::getDefaults($basePath)['migrations'];

        if (!is_dir($this->migrationPath)) {
            mkdir($this->migrationPath, 0755, true);
        }
    }

    public function write(string $name, array $upQueries, array $downQueries): string
    {
        if (empty($upQueries) && empty($downQueries)) {
            return '';
        }

        // If downQueries is empty, try to auto-generate from upQueries
        if (empty($downQueries)) {
            $downQueries = $this->generateDownQueries($upQueries);
        }

        $timestamp = date('Y_m_d_His');
        $sanitizedName = preg_replace('/[^a-z0-9_]+/', '_', strtolower($name));
        $filename = "{$timestamp}_{$sanitizedName}.php";
        $fullPath = $this->migrationPath . '/' . $filename;

        $content = $this->generateContent($upQueries, $downQueries);
        file_put_contents($fullPath, $content);

        return $fullPath;
    }

    private function generateDownQueries(array $upQueries): array
    {
        $down = [];
        // Reverse order for rollback (Drop FKs first, then Drop Tables, etc)
        $reversedUp = array_reverse($upQueries);

        $dialect = null;
        if ($this->db) {
            $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $dialect = match ($driver) {
                'mysql' => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
                'pgsql' => new \Strux\Component\Database\ORM\Dialect\PostgresDialect(),
                'sqlite' => new \Strux\Component\Database\ORM\Dialect\SqliteDialect(),
                'sqlsrv' => new \Strux\Component\Database\ORM\Dialect\SqlServerDialect(),
                default => new \Strux\Component\Database\ORM\Dialect\MySqlDialect(),
            };
        }

        foreach ($reversedUp as $query) {
            // 1. CREATE TABLE -> DROP TABLE
            if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?([^`\s]+)`?/i', $query, $matches)) {
                $table = $matches[1];
                $down[] = "DROP TABLE IF EXISTS `$table`;";
                continue;
            }

            // 2. ADD COLUMN -> DROP COLUMN
            if (preg_match('/ALTER TABLE `?([^`\s]+)`? ADD COLUMN `?([^`\s]+)`?/i', $query, $matches)) {
                $table = $matches[1];
                $column = $matches[2];
                $down[] = "ALTER TABLE `$table` DROP COLUMN `$column`;";
                continue;
            }

            // 3. ADD CONSTRAINT/FOREIGN KEY -> DROP FOREIGN KEY
            if (preg_match('/ALTER TABLE `?([^`\s]+)`? ADD CONSTRAINT `?([^`\s]+)`?/i', $query, $matches)) {
                $table = $matches[1];
                $constraint = $matches[2];
                $down[] = "ALTER TABLE `$table` DROP FOREIGN KEY `$constraint`;";
                continue;
            }

            // 4. CREATE INDEX -> DROP INDEX
            if (preg_match('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+([^\s]+)\s+ON\s+([^\s\(]+)/i', $query, $matches)) {
                $index = trim(trim($matches[1], '"'), '`');
                $table = trim(trim($matches[2], '"'), '`');
                if ($dialect) {
                    $down[] = $dialect->buildDropIndexQuery($table, $index) . ';';
                } else {
                    $down[] = "ALTER TABLE `$table` DROP INDEX `$index`;";
                }
                continue;
            }

            // 5. MODIFY COLUMN -> Cannot revert automatically without previous state
            if (str_contains($query, 'MODIFY COLUMN')) {
                $down[] = "-- TODO: Revert modification manually for: $query";
                continue;
            }

            // 6. DROP COLUMN -> Cannot revert automatically (data loss)
            if (str_contains($query, 'DROP COLUMN')) {
                $down[] = "-- TODO: Re-add dropped column manually for: $query";
                continue;
            }
        }

        return $down;
    }

    private function generateContent(array $up, array $down): string
    {
        $upString = $this->formatQueries($up);
        $downString = $this->formatQueries($down);

        return <<<PHP
<?php

use Strux\Component\Database\Migration\Migration;

return new class extends Migration {
    function up(): void
    {
        \$queries = $upString;
        \$this->executeQueries(\$queries);
    }

    function down(): void
    {
        \$queries = $downString;
        \$this->executeQueries(\$queries);
    }
};
PHP;
    }

    private function formatQueries(array $queries): string
    {
        if (empty($queries)) {
            return '[]';
        }

        $lines = [];
        $lastTable = null;

        foreach ($queries as $query) {
            // Visual grouping by table name
            if (preg_match('/(?:CREATE|ALTER|DROP)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([^`\s]+)`?/i', $query, $matches)) {
                $currentTable = $matches[1];
                if ($lastTable !== null && $lastTable !== $currentTable) {
                    $lines[] = "";
                }
                $lastTable = $currentTable;
            }

            $escaped = str_replace("'", "\'", $query);
            $lines[] = "            '$escaped',";
        }

        return "[\n" . implode("\n", $lines) . "\n        ]";
    }
}