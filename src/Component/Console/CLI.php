<?php

declare(strict_types=1);

namespace Strux\Component\Console;

use ArgumentCountError;
use Exception;
use PDO;
use Psr\Container\ContainerInterface;
use ReflectionException;
use ReflectionFunction;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\Config\DirectoryResolver;
use Strux\Component\Console\Traits\DatabaseCommands;
use Strux\Component\Console\Traits\FormCommands;
use Strux\Component\Console\Traits\Generators;
use Strux\Component\Console\Traits\QueueCommands;
use Strux\Component\Console\Traits\ServerCommands;
use Strux\Component\Console\Traits\SessionCommands;
use Strux\Component\Console\Traits\SchedulerCommands;
use Strux\Support\ContainerBridge;

class CLI
{
    use Generators;
    use FormCommands;
    use DatabaseCommands;
    use QueueCommands;
    use SessionCommands;
    use ServerCommands;
    use SchedulerCommands;

    private ContainerInterface $container;
    private array $commands = [];
    private string $rootPath;

    public function __construct(ContainerInterface $container, string $rootPath)
    {
        $this->container = $container;
        $this->rootPath = $rootPath;
        $this->registerDefaultCommands();
    }

    private function registerDefaultCommands(): void
    {
        // --- Generators ---

        // Controller
        $this->register(
            'new:controller {name} [--type]',
            'Create controller',
            function ($n = null, $o = []) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Controller name is required.\033[0m\nUsage: php bin/console new:controller <Name> [--type]\n";
                    return;
                }
                $this->createController($n, $o['type'] ?? 'web');
            }
        );
        $this->commands['g:c'] = &$this->commands['new:controller {name} [--type]'];

        // Model (Entity)
        $this->register(
            'new:entity {name} [--domain|-d] [--migrate|-m] [--uuid] [--ulid]',
            'Create entity',
            function ($n = null, $o = []) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Entity name is required.\033[0m\nUsage: php bin/console new:entity <Name> [--domain=<Name>] [--migrate] [--uuid|--ulid]\n";
                    return;
                }

                if (!empty($o['uuid']) && !empty($o['ulid'])) {
                    echo "\033[31mError: --uuid and --ulid are mutually exclusive. Use only one.\033[0m\n";
                    return;
                }

                $idType = 'none';
                if (!empty($o['uuid'])) $idType = 'uuid';
                if (!empty($o['ulid'])) $idType = 'ulid';

                $this->createModel(
                    $n,
                    $o['domain'] ?? $o['d'] ?? 'General',
                    $o['migrate'] ?? $o['m'] ?? false,
                    $idType
                );
            }
        );
        // Alias for convenience
        $this->commands['new:model {name} [--domain|-d] [--migrate|-m] [--uuid] [--ulid]'] = &$this->commands['new:entity {name} [--domain|-d] [--migrate|-m] [--uuid] [--ulid]'];
        $this->commands['g:e'] = &$this->commands['new:entity {name} [--domain|-d] [--migrate|-m] [--uuid] [--ulid]'];
        $this->commands['g:m'] = &$this->commands['new:entity {name} [--domain|-d] [--migrate|-m] [--uuid] [--ulid]'];

        // Job
        $this->register(
            'new:job {name} [--domain|-d]',
            'Create job',
            function ($n = null, $o = []) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Job name is required.\033[0m\nUsage: php bin/console new:job <Name> [--domain=<Name>]\n";
                    return;
                }
                $this->createJob(
                    $n,
                    $o['domain'] ?? $o['d'] ?? 'General'
                );
            }
        );

        // Event
        $this->register(
            'new:event {name} [--domain|-d] [--listener]',
            'Create event',
            function ($n = null, $o = []) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Event name is required.\033[0m\nUsage: php bin/console new:event <Name> [--domain=<Name>] [--listener=<Name>]\n";
                    return;
                }
                $this->createEvent(
                    $n,
                    $o['domain'] ?? $o['d'] ?? 'General',
                    $o['listener'] ?? null
                );
            }
        );

        // Listener
        $this->register(
            'new:listener {name} [--domain|-d] [--event]',
            'Create listener',
            function ($n = null, $o = []) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Listener name is required.\033[0m\nUsage: php bin/console new:listener <Name> [--domain=<Name>] [--event=<Name>]\n";
                    return;
                }
                $this->createListener(
                    $n,
                    $o['domain'] ?? $o['d'] ?? 'General',
                    $o['event'] ?? null
                );
            }
        );

        // Middleware
        $this->register(
            'new:middleware {name}',
            'Create middleware',
            function ($n = null) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Middleware name is required.\033[0m\nUsage: php bin/console new:middleware <Name>\n";
                    return;
                }
                $this->createMiddleware($n);
            }
        );

        // Registry
        $this->register(
            'new:registry {name}',
            'Create service registry',
            function ($n = null) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Registry name is required.\033[0m\nUsage: php bin/console new:registry <Name>\n";
                    return;
                }
                $this->createRegistry($n);
            }
        );

        // Scheduled Task
        $this->register(
            'new:scheduled-task {name}',
            'Create a scheduled task class',
            function ($n = null) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Scheduled task name is required.\033[0m\nUsage: php bin/console new:scheduled-task <Name>\n";
                    return;
                }
                $this->createScheduledTask($n);
            }
        );

        // Module
        $this->register(
            'new:module {name}',
            'Scaffold domain module',
            function ($n = null) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Module name is required.\033[0m\nUsage: php bin/console new:module <Name>\n";
                    return;
                }
                $this->createModule($n);
            }
        );

        // Form
        $this->register(
            'new:form {name} [--infer=] [--domain=] [--namespace=] [--force] [--exclude=] [--rules=] [--no-submit]',
            'Create a new form class',
            function ($n = null, $o = []) {
                if (!is_string($n) || empty($n)) {
                    echo "\033[31mError: Form name is required.\033[0m\nUsage: php bin/console new:form <Name> [--infer=Model] [--domain=Web] [--rules=required]\n";
                    return;
                }
                $this->createForm($n, $o);
            }
        );
        $this->commands['g:f'] = &$this->commands['new:form {name} [--infer=] [--domain=] [--namespace=] [--force] [--exclude=] [--rules=] [--no-submit]'];

        $this->register('auth:init', 'Scaffold auth', fn() => $this->initAuth());

        // --- Database ---
        $this->register('db:init', 'Init DB structure', fn() => $this->initDatabase(true));
        $this->register('db:migrate', 'Generate migration', fn($o = []) => $this->generateMigrations($o));
        $this->register('db:upgrade', 'Run migrations', fn() => $this->upgradeDatabase());
        $this->register('db:downgrade', 'Revert migration', fn() => $this->downgradeDatabase());
        $this->register('db:reset', 'Reset DB', fn() => $this->resetDatabase());
        $this->register('db:fresh', 'Fresh DB', fn() => $this->freshDatabase());
        $this->register('db:current', 'Show revision', fn() => $this->showCurrentRevision());
        $this->register('db:history', 'Show history', fn() => $this->showMigrationHistory());
        $this->register('db:seed {class?}', 'Run seeder', function ($c = null) {
            if (is_array($c)) {
                $c = null;
            }
            $this->runSeeder($c);
        });

        // --- Queue ---
        $this->register('queue:init', 'Init queue table', fn() => $this->initQueue(true));
        $this->register('queue:start', 'Start worker', fn() => $this->workQueue());
        $this->commands['queue:work'] = &$this->commands['queue:start'];

        // --- Scheduler ---
        $this->register('schedule:run', 'Run due scheduled tasks', fn() => $this->runSchedule());
        $this->register('schedule:work', 'Start schedule daemon', fn() => $this->workSchedule());

        // --- Session ---
        $this->register('session:init', 'Init session table', fn() => $this->initSession(true));

        // --- Server/Utils ---
        $this->register('var:link', 'Link the storage directory to web', fn() => $this->linkStorage());
        $this->register('var:unlink', 'Unlink the storage directory from web', fn() => $this->unlinkStorage());
        $this->register('run', 'Run dev server', function () {
            /** @var DirectoryInterface $directoryResolver */
            $directoryResolver = $this->container->has(DirectoryInterface::class)
                ? $this->container->get(DirectoryInterface::class)
                : ContainerBridge::get(DirectoryInterface::class);
            $publicDir = $directoryResolver->get('public') ?? DirectoryResolver::getDefaults($this->rootPath)['public'];
            passthru('php -S 127.0.0.1:8000 -t "' . escapeshellarg($publicDir) . '"');
        });
    }

    public function register(string $name, string $description, callable $action): void
    {
        $this->commands[$name] = compact('description', 'action');
    }

    /**
     * @throws ReflectionException
     */
    public function run(array $argv): void
    {
        if (count($argv) < 2 || in_array($argv[1], ['-h', '--help', 'help', 'list'])) {
            $this->displayHelp();
            return;
        }

        $commandInput = $argv[1];
        $inputArgs = [];
        $inputOptions = [];

        foreach (array_slice($argv, 2) as $arg) {
            if (str_starts_with($arg, '--')) {
                $option = explode('=', substr($arg, 2));
                $inputOptions[$option[0]] = $option[1] ?? true;
            } elseif (str_starts_with($arg, '-')) {
                $inputOptions[substr($arg, 1)] = true;
            } else {
                $inputArgs[] = $arg;
            }
        }

        $matchedCommand = null;
        foreach ($this->commands as $signature => $details) {
            $baseSignature = explode(' ', $signature)[0];
            if ($commandInput === $baseSignature) {
                $matchedCommand = $details;
                break;
            }
        }

        if (!$matchedCommand) {
            echo "Error: Command '$commandInput' not found.\n\n";
            $this->displayHelp();
            return;
        }

        $commandAction = $matchedCommand['action'];
        $reflection = new ReflectionFunction($commandAction);
        $params = $reflection->getParameters();

        if (!empty($params)) {
            if (count($inputArgs) < count($params)) {
                $inputArgs[] = $inputOptions;
            }
        }

        try {
            $commandAction(...$inputArgs);
        } catch (ArgumentCountError $e) {
            echo "Error: Missing arguments for command '$commandInput'.\n";
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }

    private function displayHelp(): void
    {
        echo "Custom Framework CLI\n\n";
        echo "\033[33mUsage:\033[0m\n";
        echo "  command [options] [arguments]\n\n";
        echo "\033[33mAvailable commands:\033[0m\n";

        $uniqueCommands = [];
        foreach ($this->commands as $name => $details) {
            if (str_starts_with($name, 'g:') || $name === 'queue:work' || str_starts_with($name, 'new:model'))
                continue;
            $uniqueCommands[$name] = $details;
        }
        ksort($uniqueCommands);

        foreach ($uniqueCommands as $name => $details) {
            $displayName = str_replace(['{', '}', '?'], ['<', '>', ''], $name);
            echo "  \033[32m" . str_pad($displayName, 50) . "\033[0m" . ($details['description'] ?? '') . "\n";
        }
    }

    protected function getPdo(): PDO
    {
        try {
            return $this->container->get(PDO::class) ?? ContainerBridge::resolve(PDO::class);
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    protected function initTable(string $table, string $sql, bool $verbose, ?string $checkDir = null, string $componentName = 'Table'): void
    {
        $pdo = $this->getPdo();
        $exists = $this->tableExists($pdo, $table);
        $dirExists = !$checkDir || is_dir($checkDir);

        if ($exists && $dirExists) {
            if ($verbose)
                echo "\033[32m$componentName already initialized. Table `$table` exists.\033[0m\n";
            return;
        }

        if (!$exists) {
            if ($verbose)
                echo "Creating `$table` table...\n";
            $pdo->exec($sql);
        }

        if ($checkDir && !is_dir($checkDir)) {
            mkdir($checkDir, 0755, true);
        }

        if ($verbose)
            echo "\033[32m$componentName initialized successfully.\033[0m\n";
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $result = $pdo->query("SHOW TABLES LIKE '$table'");
            return $result->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}