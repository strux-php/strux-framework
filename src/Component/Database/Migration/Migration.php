<?php

namespace Strux\Component\Database\Migration;

use PDO;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Strux\Component\Exceptions\Container\ContainerException;
use Strux\Support\ContainerBridge;
use Throwable;

abstract class Migration
{
    protected ?PDO $db;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ContainerException
     */
    public function __construct()
    {
        // We resolve the ?\PDO connection from the Container manually
        // or assume it's injected. For simplicity in generated files,
        // we can grab it globally or expect it to be set.

        // However, the easiest way for generated files is to use the
        // Application container singleton pattern if available,
        // OR rely on the runner to set it.

        // Let's use the Container to fetch it cleanly:
        $this->db = ContainerBridge::resolve(PDO::class);
    }

    abstract public function up(): void;

    abstract public function down(): void;

    /**
     * Safely executes a list of SQL queries with Foreign Key checks disabled.
     * Logs execution to the console.
     * @throws Throwable
     */
    protected function executeQueries(array $queries): void
    {
        if (empty($queries)) {
            return;
        }

        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $isMysql = in_array($driver, ['mysql', 'mariadb'], true);

        // Disable foreign key checks to allow modifications to constrained columns
        // (MySQL/MariaDB only — other databases use different mechanisms)
        if ($isMysql) {
            $this->db->exec('SET FOREIGN_KEY_CHECKS=0;');
        }

        foreach ($queries as $query) {
            // Skip comments
            if (str_starts_with(trim($query), '--')) {
                continue;
            }

            if (!$isMysql) {
                // Translate MySQL backticks to standard double quotes
                $query = str_replace('`', '"', $query);

                // MySQL-only: column ordering via AFTER is not supported
                $query = preg_replace('/\s+AFTER\s+"[^"]+"/i', '', $query);

                // ON UPDATE CURRENT_TIMESTAMP is MySQL-specific
                $query = str_ireplace(' ON UPDATE CURRENT_TIMESTAMP', '', $query);

                if ($driver === 'pgsql') {
                    // Translate AUTO_INCREMENT to SERIAL
                    $query = str_ireplace('INTEGER AUTO_INCREMENT', 'SERIAL', $query);
                    $query = str_ireplace('INT AUTO_INCREMENT', 'SERIAL', $query);
                    $query = str_ireplace(' AUTO_INCREMENT', '', $query);

                    // Postgres uses just SERIAL, not INTEGER SERIAL
                    $query = str_ireplace('INTEGER SERIAL', 'SERIAL', $query);
                    $query = str_ireplace('INT SERIAL', 'SERIAL', $query);

                    // Postgres expects boolean literals for boolean defaults
                    $query = str_ireplace('BOOLEAN NULL DEFAULT 0', 'BOOLEAN NULL DEFAULT FALSE', $query);
                    $query = str_ireplace('BOOLEAN NOT NULL DEFAULT 0', 'BOOLEAN NOT NULL DEFAULT FALSE', $query);
                    $query = str_ireplace('BOOLEAN NULL DEFAULT 1', 'BOOLEAN NULL DEFAULT TRUE', $query);
                    $query = str_ireplace('BOOLEAN NOT NULL DEFAULT 1', 'BOOLEAN NOT NULL DEFAULT TRUE', $query);
                }

                if ($driver === 'sqlsrv') {
                    // SQL Server uses brackets for identifiers
                    $query = preg_replace('/"([^"]+)"/', '[$1]', $query);
                }
            }

            echo "\033[32mExecuting:\033[0m $query" . PHP_EOL;

            try {
                $this->db->exec($query);
            } catch (Throwable $e) {
                // Ensure we re-enable checks even if a query fails
                if ($isMysql) {
                    $this->db->exec('SET FOREIGN_KEY_CHECKS=1;');
                }
                throw $e;
            }
        }

        // Re-enable foreign key checks
        if ($isMysql) {
            $this->db->exec('SET FOREIGN_KEY_CHECKS=1;');
        }
    }
}