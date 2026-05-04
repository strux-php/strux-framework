<?php

declare(strict_types=1);

namespace Strux\Component\Console\Traits;

use Exception;
use PDO;
use Strux\Component\Config\Config;
use Strux\Component\Database\Migration\Migration;

use Strux\Component\Database\MigrationGenerator;
use Strux\Component\Database\Seeder\SeederRunner;

trait DatabaseCommands
{
    abstract protected function getPdo(): PDO;

    abstract protected function initTable(
        string  $table,
        string  $sql,
        bool    $verbose,
        ?string $checkDir = null,
        string  $componentName = 'Table'
    ): void;

    private function getMigrationTable(): string
    {
        try {
            $config = $this->container->get(Config::class);
            return $config->get('database.migrations') ?? '_migrations';
        } catch (Exception $e) {
            return '_migrations';
        }
    }

    private function initDatabase(bool $verbose = false): void
    {
        $table = $this->getMigrationTable();
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 4);
        $migrationDir = $rootPath . '/database/migrations';

        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            batch INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;";

        $this->initTable($table, $sql, $verbose, $migrationDir, 'Database');
    }

    private function generateMigrations(array $options = []): void
    {
        try {
            $this->initDatabase();
            $config = $this->container->get(Config::class);
            $db = $this->container->get(PDO::class);

            $model = $options['m'] ?? $options['model'] ?? null;
            $name = $options['n'] ?? $options['name'] ?? null;

            echo "Scanning database changes...\n";
            if ($model) {
                echo "Targeting specific model: $model\n";
            }

            $generator = new MigrationGenerator($config, $db);
            $generator->generate($model, $name);
        } catch (Exception $e) {
            echo "Migration generation failed: " . $e->getMessage() . "\n";
        }
    }

    private function upgradeDatabase(): void
    {
        $pdo = $this->getPdo();

        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 4);
        $table = $this->getMigrationTable();

        $this->initDatabase();

        $ranMigrations = $pdo->query("SELECT migration FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $migrationFiles = glob($rootPath . '/database/migrations/*.php');
        sort($migrationFiles);

        $batch = empty($ranMigrations) ? 1 : $pdo->query("SELECT MAX(batch) FROM `$table`")->fetchColumn() + 1;

        $migrationsToRun = array_filter($migrationFiles, fn($file) => !in_array(basename($file), $ranMigrations));

        if (empty($migrationsToRun)) {
            echo "No pending migrations.\n";
            return;
        }

        echo "Running migrations (Batch $batch)...\n";

        foreach ($migrationsToRun as $file) {
            echo "Migrating: " . basename($file) . "\n";
            $migration = require $file;
            if ($migration instanceof Migration) {
                $migration->up();
            }

            $stmt = $pdo->prepare("INSERT INTO `$table` (migration, batch) VALUES (?, ?)");
            $stmt->execute([basename($file), $batch]);
        }

        echo "Upgrade completed successfully.\n";
    }

    private function downgradeDatabase(): void
    {
        $pdo = $this->getPdo();

        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 4);
        $table = $this->getMigrationTable();

        $lastBatch = $pdo->query("SELECT MAX(batch) FROM `$table`")->fetchColumn();

        if (!$lastBatch) {
            echo "No migrations to revert.\n";
            return;
        }

        $migrations = $pdo->prepare("SELECT migration FROM `$table` WHERE batch = ? ORDER BY id DESC");
        $migrations->execute([$lastBatch]);
        $filesToRevert = $migrations->fetchAll(PDO::FETCH_COLUMN);

        echo "Reverting Batch $lastBatch...\n";

        foreach ($filesToRevert as $migrationName) {
            $filePath = $rootPath . '/database/migrations/' . $migrationName;
            if (file_exists($filePath)) {
                echo "Reverting: $migrationName\n";
                $migration = require $filePath;
                if ($migration instanceof Migration) {
                    $migration->down();
                }
            } else {
                echo "Warning: Migration file '$migrationName' not found. Skipping rollback logic, but removing record.\n";
            }
            $del = $pdo->prepare("DELETE FROM `$table` WHERE migration = ?");
            $del->execute([$migrationName]);
        }

        echo "Downgrade completed successfully.\n";
    }

    /**
     * @throws Exception
     */
    private function resetDatabase(): void
    {
        echo "WARNING: This will drop all tables in the database. Are you sure? (yes/no) [no]: ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        if ($line !== 'yes') {
            echo "Operation cancelled.\n";
            return;
        }

        echo "Resetting database...\n";
        $this->dropAllTables();
        echo "All tables dropped.\n";

        $this->upgradeDatabase();
    }

    /**
     * @throws Exception
     */
    private function freshDatabase(): void
    {
        echo "WARNING: This will DROP ALL TABLES and DELETE ALL MIGRATION FILES. Are you sure? (yes/no) [no]: ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        if ($line !== 'yes') {
            echo "Operation cancelled.\n";
            return;
        }

        echo "Fresh start...\n";

        $this->dropAllTables();
        echo "All tables dropped.\n";

        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 4);

        $migrationDir = realpath($rootPath . '/database/migrations');

        if ($migrationDir && is_dir($migrationDir)) {
            $files = glob($migrationDir . '/*.php');

            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        if (unlink($file)) {
                            echo "Removed: " . basename($file) . "\n";
                        } else {
                            echo "\033[31mFailed to remove: " . basename($file) . " (Check permissions or file locks)\033[0m\n";
                            $error = error_get_last();
                            if ($error) {
                                echo "  -> " . $error['message'] . "\n";
                            }
                        }
                    }
                }
            }
        }

        echo "Old migration files deletion process finished.\n\n";

        // Generate fresh migration based on current Models
        $this->generateMigrations(['n' => 'initial_schema']);

        // Re-run migrations
        $this->upgradeDatabase();

        echo "Database is now fresh and synced with Models.\n";
    }

    /**
     * @throws Exception
     */
    private function dropAllTables(): void
    {
        $pdo = $this->getPdo();


        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0;');

            $stmt = $pdo->query('SHOW TABLES');
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $pdo->exec("DROP TABLE IF EXISTS `$table`");
                echo "Dropped table: $table\n";
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
        } catch (Exception $e) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
            throw $e;
        }
    }

    private function showCurrentRevision(): void
    {
        $pdo = $this->getPdo();
        $table = $this->getMigrationTable();
        try {
            $last = $pdo->query("SELECT migration, batch, created_at FROM `$table` ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

            if ($last) {
                echo "Current Revision: {$last['migration']} (Batch {$last['batch']})\n";
                echo "Applied At: {$last['created_at']}\n";
            } else {
                echo "No migrations applied.\n";
            }
        } catch (Exception $e) {
            echo "Migrations table not found. Run 'php console db:init' first.\n";
        }
    }

    private function showMigrationHistory(): void
    {
        $pdo = $this->getPdo();
        $table = $this->getMigrationTable();
        try {
            $history = $pdo->query("SELECT * FROM `$table` ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($history)) {
                echo "No migration history found.\n";
                return;
            }

            echo str_pad("ID", 5) . str_pad("Batch", 8) . str_pad("Applied At", 22) . "Migration\n";
            echo str_repeat("-", 80) . "\n";

            foreach ($history as $row) {
                echo str_pad((string)$row['id'], 5) .
                    str_pad((string)$row['batch'], 8) .
                    str_pad((string)$row['created_at'], 22) .
                    $row['migration'] . "\n";
            }
        } catch (Exception $e) {
            echo "Migrations table not found. Run 'php console db:init' first.\n";
        }
    }

    private function runSeeder(?string $class): void
    {
        try {
            /** @var SeederRunner $runner */
            $runner = $this->container->get(SeederRunner::class);

            if ($class) {
                if (!str_contains($class, '\\')) {
                    $class = "App\\Database\\Seeds\\$class";
                }
                echo "Seeding class: $class\n";
                $runner->run($class);
            } else {
                $default = "App\\Infrastructure\\Database\\Seeds\\DatabaseSeeder";
                echo "No class provided. Attempting to run default: $default\n";
                if (class_exists($default)) {
                    $runner->run($default);
                } else {
                    echo "Error: No seeder class provided and $default not found.\n";
                }
            }
            echo "Seeding completed.\n";
        } catch (Exception $e) {
            echo "Seeding failed: " . $e->getMessage() . "\n";
        }
    }
}