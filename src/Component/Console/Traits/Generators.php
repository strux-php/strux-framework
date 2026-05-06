<?php

declare(strict_types=1);

namespace Strux\Component\Console\Traits;

use Exception;
use Strux\Component\Config\Config;
use Strux\Support\Helpers\Utils;

trait Generators
{
    /**
     * @param string $name
     * @param string $type 'web' or 'api'
     */
    private function createController(string $name, string $type = 'web'): void
    {
        try {
            // Determine subtype based on input or suffix
            $subType = strtolower($type);
            if ($subType !== 'api') {
                $subType = 'web';
            }

            // Map to getPathForType keys
            $key = $subType === 'api' ? 'controller_api' : 'controller_web';

            $filePath = $this->getPathForType($key, $name);
            $this->ensureDirectoryExists($filePath);

            if (file_exists($filePath)) {
                echo "\033[31mConflict: $filePath already exists.\033[0m\n";
                return;
            }

            $namespace = $this->getNamespaceFromPath($filePath);
            $className = basename($name);
            $route = Utils::toSnakeCase($className);

            if ($subType === 'web') {
                $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Strux\Component\Routing\Attributes\Route;
use Strux\Component\Routing\Attributes\Prefix;
use Strux\Component\Http\Response;
use Strux\Component\Http\Controller\Web\Controller;

#[Prefix('/{$route}')]
class {$className} extends Controller
{
    #[Route('/', methods: ['GET'], name: '{$route}.index')]
    public function index(): Response
    {
        return \$this->view('index', [
            'title' => '{$className}'
        ]);
    }
}
PHP;
            } else {
                // API Template
                $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Strux\Component\Routing\Attributes\ApiController;
use Strux\Component\Routing\Attributes\ApiRoute;
use Strux\Component\Http\Attributes\Consumes;
use Strux\Component\Routing\Attributes\Prefix;
use Strux\Component\Http\Attributes\Produces;
use Strux\Component\Http\ApiResponse;
use Strux\Component\Http\Controller\Api\Controller;

#[ApiController]
#[Prefix('/api/{$route}')]
#[Produces('application/json')]
#[Consumes('application/json')]
class {$className} extends Controller
{
    #[ApiRoute('/', methods: ['GET'], name: 'api.{$route}.index')]
    public function index(): ApiResponse
    {
        return \$this->json(['message' => 'Welcome to {$className}']);
    }
}
PHP;
            }

            file_put_contents($filePath, $content);
            echo "Controller created successfully: $filePath\n";

        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function createModel(string $name, string $domain = 'General', bool $createMigration = false): void
    {
        try {
            $filePath = $this->getPathForType('entity', $name, $domain);
            $this->ensureDirectoryExists($filePath);

            if (file_exists($filePath)) {
                echo "\033[31mConflict: $filePath already exists.\033[0m\n";
                return;
            }

            $namespace = $this->getNamespaceFromPath($filePath);
            $className = basename($name);

            $tableName = Utils::getPluralName($className);

            $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Strux\Component\Database\Attributes\Table;
use Strux\Component\Database\Attributes\Column;
use Strux\Component\Database\Attributes\Id;
use Strux\Component\Database\Types\Field;
use Strux\Component\Model\Model;

#[Table('{$tableName}')]
class {$className} extends Model
{
    #[Id, Column]
    public ?int \$id = null;
    
    #[Column(type: Field::string, length: 150)]
    public string \$name;
}
PHP;
            file_put_contents($filePath, $content);
            echo "Entity created successfully: $filePath\n";

            if ($createMigration) {
                require_once $filePath;
                $this->generateMigrations(['m' => $name]);
            }

        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function createJob(string $name, string $domain = 'General'): void
    {
        try {
            $filePath = $this->getPathForType('job', $name, $domain);
            $this->ensureDirectoryExists($filePath);

            if (file_exists($filePath)) {
                echo "\033[31mConflict: $filePath already exists.\033[0m\n";
                return;
            }

            $namespace = $this->getNamespaceFromPath($filePath);
            $className = basename($name);

            $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Strux\Component\Queue\Job;

class {$className} extends Job
{
    public function __construct()
    {
        //
    }

    public function handle(): void
    {
        // Process the job
    }
}
PHP;
            file_put_contents($filePath, $content);
            echo "Job created successfully: $filePath\n";
        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function createEvent(string $name, string $domain = 'General', ?string $listener = null): void
    {
        try {
            $filePath = $this->getPathForType('event', $name, $domain);
            $this->ensureDirectoryExists($filePath);

            if (file_exists($filePath)) {
                echo "\033[31mConflict: $filePath already exists.\033[0m\n";
                return;
            }

            $namespace = $this->getNamespaceFromPath($filePath);
            $className = basename($name);

            $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

class {$className}
{
    public function __construct()
    {
        //
    }
}
PHP;
            file_put_contents($filePath, $content);
            echo "Event created successfully: $filePath\n";

            if ($listener) {
                $this->createListener($listener, $domain, $name);
            }
        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function createListener(string $name, string $domain = 'General', ?string $event = null): void
    {
        try {
            $filePath = $this->getPathForType('listener', $name, $domain);
            $this->ensureDirectoryExists($filePath);

            if (file_exists($filePath)) {
                echo "\033[31mConflict: $filePath already exists.\033[0m\n";
                return;
            }

            $namespace = $this->getNamespaceFromPath($filePath);
            $className = basename($name);

            // Resolve Event Namespace for import
            $eventImport = "";
            $eventType = "object";

            if ($event) {
                // Assume Event is in the same domain
                $eventPath = $this->getPathForType('event', $event, $domain);
                $eventNamespace = $this->getNamespaceFromPath($eventPath);
                $eventFullClass = $eventNamespace . '\\' . $event;

                $eventImport = "use {$eventFullClass};";
                $eventType = $event;
            }

            $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$eventImport}

class {$className}
{
    public function __construct()
    {
        //
    }

    public function handle({$eventType} \$event): void
    {
        // Handle the event
    }
}
PHP;
            file_put_contents($filePath, $content);
            echo "Listener created successfully: $filePath\n";
        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function createMiddleware(string $name): void
    {
        try {
            $filePath = $this->getPathForType('middleware', $name);
            $this->ensureDirectoryExists($filePath);

            if (file_exists($filePath)) {
                echo "\033[31mConflict: $filePath already exists.\033[0m\n";
                return;
            }

            $namespace = $this->getNamespaceFromPath($filePath);
            $className = basename($name);

            $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class {$className} implements MiddlewareInterface
{
    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
    {
        // Logic before request
        
        \$response = \$handler->handle(\$request);
        
        // Logic after response
        
        return \$response;
    }
}
PHP;
            file_put_contents($filePath, $content);
            echo "Middleware created successfully: $filePath\n";
        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function createRegistry(string $name): void
    {
        try {
            $filePath = $this->getPathForType('registry', $name);
            $this->ensureDirectoryExists($filePath);

            if (file_exists($filePath)) {
                echo "\033[31mConflict: $filePath already exists.\033[0m\n";
                return;
            }

            $namespace = $this->getNamespaceFromPath($filePath);
            $className = basename($name);

            $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Strux\Bootstrapping\Registry\ServiceRegistry;
use Psr\Container\ContainerInterface;

class {$className} extends ServiceRegistry
{
    public function build(): void
    {
        // Register services here
        // \$this->container->singleton(SomeInterface::class, SomeImplementation::class);
    }
    
    public function init(\$app): void {}
}
PHP;
            file_put_contents($filePath, $content);
            echo "Registry created successfully: $filePath\n";
        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function createModule(string $name): void
    {
        try {
            $appMode = $this->getAppMode();

            if ($appMode !== 'domain') {
                echo "\033[31mError: Modules are only available in 'domain' mode.\033[0m\n";
                echo "Current mode: $appMode. Check etc/app.php to switch modes.\n";
                return;
            }

            $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 5);
            $basePath = $root . '/src/Domain/' . $name;

            if (is_dir($basePath)) {
                echo "\033[31mModule '$name' already exists.\033[0m\n";
                return;
            }

            // Standard Domain Structure
            $dirs = [
                'Entity',
                'Event',
                'Job',
                'Listener',
                'Repository',
                'Service',
                'Security'
            ];

            foreach ($dirs as $dir) {
                $path = $basePath . '/' . $dir;
                if (!is_dir($path)) {
                    mkdir($path, 0755, true);
                }
            }

            echo "\033[32mDomain module '$name' scaffolded successfully.\033[0m\n";
            echo "Location: src/Domain/$name\n";

        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function initAuth(): void
    {
        try {
            $domain = 'Identity';

            // 1. Create User Model
            $userModelPath = $this->getPathForType('entity', 'User', $domain);
            $this->ensureDirectoryExists($userModelPath);
            $namespace = $this->getNamespaceFromPath($userModelPath);

            $userContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use DateTime;
use Strux\Auth\Traits\WillAuthenticate;
use Strux\Component\Database\Attributes\Column;
use Strux\Component\Database\Attributes\Id;
use Strux\Component\Database\Attributes\Table;
use Strux\Component\Database\Attributes\Unique;
use Strux\Component\Database\Types\Field;
use Strux\Component\Model\Attributes\BelongsToMany;
use Strux\Component\Model\Model;
use Strux\Support\Collection;

#[Table('users')]
class User extends Model
{
    use WillAuthenticate;

    #[Id, Column]
    public ?int \$id = null;

    #[Column]
    public string \$firstname;

    #[Column]
    public string \$lastname;

    #[Column, Unique]
    public string \$email;

    #[Column]
    public string \$password;

    #[Column(type: Field::timestamp, currentTimestamp: true)]
    public ?DateTime \$createdAt;

    #[Column(type: Field::timestamp, currentTimestamp: true, onUpdateCurrentTimestamp: true)]
    public ?DateTime \$updatedAt = null;

    #[BelongsToMany(related: Roles::class)]
    public Collection \$roles;

    /**
     * Check if user has a specific role (string or array).
     */
    public function hasRole(string|array \$roles): bool
    {
        \$roles = is_array(\$roles) ? \$roles : [\$roles];
        // Ensure roles are loaded
        if (!isset(\$this->roles)) {
            \$this->roles = \$this->roles()->get(); 
        }
        
        foreach (\$this->roles as \$role) {
            if (in_array(\$role->slug, \$roles) || in_array(\$role->name, \$roles)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has a specific permission (via roles).
     */
    public function hasPermission(string|array \$permissions): bool
    {
        \$permissions = is_array(\$permissions) ? \$permissions : [\$permissions];
        // Logic to check permissions through roles...
        // This usually requires eager loading roles.permissions
        return false; // Implement detailed logic based on your depth preference
    }
}
PHP;
            file_put_contents($userModelPath, $userContent);
            echo "\033[32mUser entity created.\033[0m\n";

            // 2. Create Roles Model
            $roleModelPath = $this->getPathForType('entity', 'Roles', $domain);
            $roleContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Strux\Component\Database\Attributes\Column;
use Strux\Component\Database\Attributes\Id;
use Strux\Component\Database\Attributes\Table;
use Strux\Component\Database\Attributes\Unique;
use Strux\Component\Model\Attributes\BelongsToMany;
use Strux\Component\Model\Model;
use Strux\Support\Collection;

#[Table('roles')]
class Roles extends Model
{
    #[Id, Column]
    public ?int \$id = null;

    #[Column]
    public string \$name;

    #[Column, Unique]
    public string \$slug;

    #[Column(nullable: true)]
    public ?string \$description = null;

    #[BelongsToMany(related: User::class)]
    public Collection \$users;

    #[BelongsToMany(related: Permissions::class)]
    public Collection \$permissions;
}
PHP;
            file_put_contents($roleModelPath, $roleContent);
            echo "\033[32mRole entity created.\033[0m\n";

            // 3. Create Permissions Model
            $permModelPath = $this->getPathForType('entity', 'Permissions', $domain);
            $permContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Strux\Component\Database\Attributes\Column;
use Strux\Component\Database\Attributes\Id;
use Strux\Component\Database\Attributes\Table;
use Strux\Component\Database\Attributes\Unique;
use Strux\Component\Model\Attributes\BelongsToMany;
use Strux\Component\Model\Model;
use Strux\Support\Collection;

#[Table('permissions')]
class Permissions extends Model
{
    #[Id, Column]
    public ?int \$id = null;

    #[Column]
    public string \$name;

    #[Column, Unique]
    public string \$slug;

    #[BelongsToMany(related: Roles::class)]
    public Collection \$roles;
}
PHP;
            file_put_contents($permModelPath, $permContent);
            echo "\033[32mPermission entity created.\033[0m\n";

            echo "\nDo you want to generate the migration file now? (yes/no) [yes]: ";
            $handle = fopen("php://stdin", "r");
            $line = trim(fgets($handle));
            if ($line === '' || $line === 'yes') {
                echo "Generating migrations for Auth system...\n";
                // Calling generate without a specific model will scan ALL models,
                // picking up User, Roles, Permissions and creating their tables + pivot tables
                $this->generateMigrations(['n' => 'create_auth_tables']);
            }
        } catch (Exception $e) {
            echo "\033[31mError initializing auth: " . $e->getMessage() . "\033[0m\n";
        }
    }

    /**
     * Calculates the file path based on type and domain.
     * * @throws Exception
     */
    protected function getPathForType(string $type, string $name, ?string $domain = 'General'): string
    {
        // Define root path if not defined
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 5);
        $base = $root . '/src';

        // Normalize name (remove .php if present)
        $name = str_replace('.php', '', $name);

        $appMode = $this->getAppMode();

        if ($appMode === 'standard') {
            // --- Standard / Flat Structure ---
            return match ($type) {
                'entity' => "$base/Entity/$name.php",
                'event' => "$base/Event/$name.php",
                'job' => "$base/Job/$name.php",
                'listener' => "$base/Listener/$name.php",
                'controller_web' => "$base/Controller/$name.php",
                'controller_api' => "$base/Controller/Api/$name.php",
                'request' => "$base/Request/$name.php",
                'middleware' => "$base/Middleware/$name.php",
                'registry' => "$base/Registry/$name.php",
                'seeder' => "$base/Database/Seeds/$name.php",

                default => throw new Exception("Unknown type: $type"),
            };
        }

        // --- Domain / DDD Structure (Default) ---
        return match ($type) {
            'entity' => "$base/Domain/$domain/Entity/$name.php",
            'event' => "$base/Domain/$domain/Event/$name.php",
            'job' => "$base/Domain/$domain/Job/$name.php",
            'listener' => "$base/Domain/$domain/Listener/$name.php",

            // Http Logic (Global)
            'controller_web' => "$base/Http/Controllers/Web/$name.php",
            'controller_api' => "$base/Http/Controllers/Api/$name.php",
            'request' => "$base/Http/Request/$name.php",
            'middleware' => "$base/Http/Middleware/$name.php",
            'registry' => "$base/Registry/$name.php",

            // Infrastructure
            'seeder' => "$base/Infrastructure/Database/Seeds/$name.php",

            default => throw new Exception("Unknown type: $type"),
        };
    }

    /**
     * Helper to retrieve the application mode from config
     */
    private function getAppMode(): string
    {
        if (property_exists($this, 'container') && $this->container->has(Config::class)) {
            return $this->container->get(Config::class)->get('app.mode', 'domain');
        }
        return 'domain';
    }

    /**
     * Converts a file path into a PHP Namespace based on PSR-4 rules.
     * Assumes 'src' maps to 'Application'.
     */
    private function getNamespaceFromPath(string $filePath): string
    {
        $dir = dirname($filePath);

        // Find the position of '/src/'
        $pos = strpos($dir, 'src');

        if ($pos === false) {
            // Fallback for edge cases or testing
            return 'App';
        }

        // Get everything after src/
        $relativePath = substr($dir, $pos + 4); // +4 for length of "src/" or "src" + 1
        $relativePath = ltrim($relativePath, '/\\');

        // Replace slashes with backslashes
        $ns = str_replace('/', '\\', $relativePath);

        return "App\\$ns";
    }

    private function ensureDirectoryExists(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}