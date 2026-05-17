<?php

declare(strict_types=1);

namespace Strux\Component\Database;

use Exception;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use Strux\Component\Config\Config;
use Strux\Component\Database\Attributes\Table;
use Strux\Component\Database\Migration\Blueprint;
use Strux\Component\Database\Migration\MigrationWriter;
use Strux\Component\Database\Migration\ModelBuilder;
use Strux\Component\Config\DirectoryInterface;

class MigrationGenerator
{
    private Config $config;
    private PDO $db;
    private MigrationWriter $writer;
    private string $srcPath;
    private DirectoryInterface $directories;

    public function __construct(Config $config, PDO $db, DirectoryInterface $directories)
    {
        $this->config = $config;
        $this->db = $db;
        $this->directories = $directories;
        
        $projectRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 5);
        $this->writer = new MigrationWriter($projectRoot);
        
        $this->srcPath = rtrim($this->directories->get('app'), '/\\');
    }
    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function generate(?string $targetModel = null, ?string $migrationName = null): void
    {
        $defaultConnection = $this->config->get('database.default');
        $dbConfig = $this->config->get("database.connections.$defaultConnection");

        // Scan all available models first
        $availableModels = $this->scanModels();

        echo "Found " . count($availableModels) . " models with #[Table] attribute.\n";
        echo "Generating migration for " . ($targetModel ? "model '$targetModel'" : "all models") . ".\n";
        echo "----------------------------------------\n";
        echo "src Path: " . $this->srcPath . "\n";

        if ($targetModel) {
            // If the user passed a short name like "User", find the full class
            if (!str_contains($targetModel, '\\')) {
                $targetModel = $this->resolveModelClass($targetModel, $availableModels);
            }

            if (!class_exists($targetModel)) {
                echo "\033[31mError: Model class '$targetModel' not found.\033[0m\n";
                return;
            }
            $models = [$targetModel];
        } else {
            $models = $availableModels;
        }

        $tableQueries = [];
        $constraintQueries = [];
        $downQueries = [];

        foreach ($models as $modelClass) {
            // 1. Tables
            $builder = new ModelBuilder($modelClass, $this->db, $dbConfig);
            $sql = $builder->generateSql();
            if (!empty($sql)) {
                $tableQueries = array_merge($tableQueries, (array) $sql);
            }

            // 2. Pivots
            $pivotSql = Blueprint::generatePivotTableSql($modelClass, $this->db, $dbConfig);
            if (!empty($pivotSql)) {
                $tableQueries = array_merge($tableQueries, $pivotSql);
            }

            // 3. Unique Indexes
            $uniqueSql = Blueprint::generateUniqueConstraints($modelClass);
            if (!empty($uniqueSql)) {
                $existingIndexes = $this->getExistingIndexes($modelClass);
                foreach ($uniqueSql as $indexName => $query) {
                    if (in_array($indexName, $existingIndexes)) {
                        continue;
                    }
                    $tableQueries[] = $query;
                }
            }

            // 4. Constraints
            $fkSql = Blueprint::generateForeignKeyConstraints($modelClass, $this->db);
            if (!empty($fkSql)) {
                $constraintQueries = array_merge($constraintQueries, $fkSql);
            }

            $pivotFkSql = Blueprint::generatePivotConstraints($modelClass, $this->db);
            if (!empty($pivotFkSql)) {
                $constraintQueries = array_merge($constraintQueries, $pivotFkSql);
            }
        }

        // Post-Processing: Separate Drops and Adds to prevent key errors
        $dropFkQueries = [];
        $otherTableQueries = [];

        foreach ($tableQueries as $key => $query) {
            if (str_contains($query, 'DROP FOREIGN KEY')) {
                $dropFkQueries[$key] = $query;
            } else {
                $otherTableQueries[$key] = $query;
            }
        }

        $upQueries = array_merge(
            array_values($dropFkQueries),
            array_values($otherTableQueries),
            array_values($constraintQueries)
        );

        if (!empty($upQueries)) {
            if ($migrationName) {
                $finalName = $migrationName;
            } elseif ($targetModel) {
                $shortName = strtolower(substr(strrchr($targetModel, "\\"), 1));
                $finalName = "update_{$shortName}_table";
            } else {
                $finalName = 'auto_generated_diff';
            }

            $path = $this->writer->write($finalName, $upQueries, $downQueries);
            echo "\033[32mMigration file generated at: $path\033[0m\n";
        } else {
            echo "No database changes detected.\n";
        }
    }

    /**
     * @throws ReflectionException
     */
    private function getExistingIndexes(string $modelClass): array
    {
        $reflection = new ReflectionClass($modelClass);
        $tableAttribute = $reflection->getAttributes(Table::class)[0] ?? null;
        if (!$tableAttribute) {
            return [];
        }
        $tableName = $tableAttribute->newInstance()->name;

        try {
            $stmt = $this->db->query("SHOW INDEX FROM `$tableName`");
            $indexes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $indexes[] = $row['Key_name'];
            }
            return $indexes;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Recursively scans the src/Domain directory for classes with #[Table] attributes.
     */
    private function scanModels(): array
    {
        $models = [];
        // Target the Domain folder specifically
        $path = $this->srcPath . '/Domain';

        if (!is_dir($path)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Convert file path to Namespace
                // E.g. C:\xampp\htdocs\custom\src\Domain\Identity\Entity\User.php
                // Becomes: Application\Domain\Identity\Entity\User

                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($this->srcPath) + 1); // Remove .../src/
                $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
                $className = "App\\" . $classPath;

                if (class_exists($className)) {
                    try {
                        $reflection = new ReflectionClass($className);
                        // Check if it's instantiable and has the #[Table] attribute
                        if (!$reflection->isAbstract() && !empty($reflection->getAttributes(Table::class))) {
                            echo "Scanning model: $className\n";
                            $models[] = $className;
                        }
                    } catch (ReflectionException $e) {
                        continue;
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Helper to find a full class name from a short name (e.g. "User")
     * @throws Exception
     */
    private function resolveModelClass(string $shortName, array $availableModels): string
    {
        $matches = [];
        foreach ($availableModels as $fqcn) {
            $classBase = basename(str_replace('\\', '/', $fqcn));
            if ($classBase === $shortName) {
                $matches[] = $fqcn;
            }
        }

        if (count($matches) === 0) {
            throw new Exception("Could not find model '$shortName' in any Domain.");
        }

        if (count($matches) > 1) {
            throw new Exception("Ambiguous model name '$shortName'. Found in: " . implode(', ', $matches) . ". Please use full namespace.");
        }

        return $matches[0];
    }
}

