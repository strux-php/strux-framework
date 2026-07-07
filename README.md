# Strux Framework

Strux is a modern, lightweight, **attribute-driven PHP framework** for building web applications and APIs.
It combines PHP 8.4+ features with a clean architecture — Active Record ORM, attribute-based routing,
built-in auth, scheduler, queue, event dispatcher, and validation — while keeping a minimal core.

---

## Features

- **Attribute-driven everything** — Routes (`#[Route]`), ORM schema (`#[Entity]`, `#[Column]`), auth (`#[Authorize]`), validation (`#[Validate]`), scheduling (`#[Schedule]`)
- **Active Record ORM** with relationships (`#[OwnedBy]`, `#[OwnsMany]`, `#[OwnedByMany]`, polymorphic variants), JSON queries, pagination, soft deletes, and query caching
- **Plates templating** (default, Twig available via adapter)
- **Task Scheduler** — cron-expression and named-frequency task scheduling with mutex locking, output capture, conditional execution, and events
- **Queue system** — database-driven background job processing
- **Auth system** — Session and JWT sentinels, roles & permissions, email verification, password recovery, "remember me"
- **Form system** — attribute-driven forms with auto-binding to requests, models, or arrays
- **Event dispatcher** (PSR-14)
- **CLI tooling** for rapid development (scaffolding, migrations, queue, scheduler)
- **Multi-database support** — MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle
- **Zero external dependencies** (beyond PHP extensions)

---

## Requirements

- PHP **8.4+**
- Composer
- PDO extension (for database access)
- MBString, XML extensions

---

## Installation

```bash
composer create-project strux/strux-app my-app
cd my-app
php bin/console run
```

---

## Configuration

Configuration files live in `src/Config/`. Each config is a PHP class implementing `ConfigInterface`:

```
src/Config/
  App.php         # Application name, URL, debug mode
  Database.php    # Database connections (SQLite, MySQL, PostgreSQL, etc.)
  Auth.php        # Sentinels, password rules, email verification
  Cache.php       # Cache driver (filesystem, array, APCu)
  Queue.php       # Queue connection (sync, database)
  Scheduler.php   # Timezone, environments, maintenance mode
  Maintenance.php # Maintenance mode settings
  Plates.php      # Plates configuration (default)
  Twig.php        # Twig adapter configuration
  Session.php     # Session driver and options
  Cors.php        # CORS middleware configuration
```

Environment variables are loaded from `.env`:

```bash
cp .env.example .env
```

```env
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=my_app
DB_USERNAME=postgres
DB_PASSWORD=secret
```

---

## Directory Structure

```
bin/          # CLI entry point (console)
src/          # Application source code
  App.php     # Application class
  Config/     # Configuration files
  Domain/     # Domain-driven modules (Entity, Job, Listener, Service)
  Http/
    Controllers/
      Web/    # Web controllers (HTML)
      Api/    # API controllers (JSON)
  Infrastructure/
    Database/
      Migrations/
      Seeds/
  Registry/   # Service registries
templates/    # View templates (Plates or Twig)
web/          # Public entry point (index.php)
var/          # Cache, logs, sessions
  cache/
  logs/
```

---

## Routing

Routes are defined via PHP attributes directly on controller methods:

```php
use Strux\Component\Routing\Attributes\Route;
use Strux\Component\Routing\Attributes\Prefix;
use Strux\Component\Routing\Attributes\RouteGroup;

#[Prefix('/artworks')]
class ArtworkController extends Controller
{
    #[Route('', methods: ['GET'], name: 'artworks.index')]
    public function index(): Response
    {
        return $this->view('artworks/index', ['artworks' => Artwork::all()]);
    }

    #[Route('/:id', methods: ['GET'], name: 'artworks.show')]
    public function show(string $id): Response
    {
        return $this->view('artworks/show', ['artwork' => Artwork::findOrFail($id)]);
    }
}
```

No separate route files needed.

---

## Controllers

Controllers live in `src/Http/Controllers/Web/` (HTML) or `src/Http/Controllers/Api/` (JSON).
They extend `Strux\Component\Http\Controller\Web\Controller` or `Api\Controller`.

```php
#[Prefix('/dashboard')]
#[Middleware([AuthorizationMiddleware::class])]
class DashboardController extends Controller
{
    public function __construct(
        private readonly ArtworkRepository $artworks
    ) {}

    #[Route('', methods: ['GET'], name: 'dashboard.index')]
    public function index(): Response
    {
        return $this->view('dashboard/index', [
            'stats' => $this->artworks->getDashboardStats()
        ]);
    }
}
```

Dependencies are injected automatically via the container.

---

## Middleware

Middleware classes implement `MiddlewareInterface` and are applied via the `#[Middleware]` attribute:

```php
#[Middleware([AuthMiddleware::class])]
#[Route('/admin', methods: ['GET'])]
public function admin(): Response { ... }
```

Global middleware is configured in `src/Registry/MiddlewareRegistry.php`.

Built-in middleware: `AuthorizationMiddleware`, `GuestMiddleware`, `EnsureEmailIsVerified`, `CorsMiddleware`, `CsrfMiddleware`.

---

## Views

Strux uses **Plates** as its default templating engine, with **Twig** available via a built-in adapter.

```php
return $this->view('pages/home', ['title' => 'Welcome']);
```

### Plates (Default)

```php
<?php $this->layout('layouts/app', ['title' => 'Home']) ?>
<h1><?= $this->e($title) ?></h1>
```

### Twig (Adapter)

```twig
{% extends 'layout.html.twig' %}
{% block content %}
    <h1>{{ title }}</h1>
{% endblock %}
```

Configure your engine in `src/Config/View.php`.

---

## Database & ORM

Strux includes an **Active Record ORM** driven by PHP attributes.

### Defining a Model

```php
use Strux\Component\Database\Schema\Attributes\Entity;
use Strux\Component\Database\Schema\Attributes\Column;
use Strux\Component\Database\Schema\Attributes\Id;
use Strux\Component\Database\Schema\Types\Field;
use Strux\Component\Database\ORM\Model;

#[Entity(table: 'users')]
class User extends Model
{
    #[Id(autoincrement: false, autoGenerate: 'uuid')]
    #[Column(type: Field::uuid)]
    public string $id = '';

    #[Column(type: Field::string, length: 150)]
    public string $name;

    #[Column(type: Field::string, unique: true)]
    public string $email;
}
```

### Basic Usage

```php
// Create
$user = new User();
$user->name = 'John';
$user->email = 'john@example.com';
$user->save();

// Find
$user = User::find($id);
$users = User::where('active', true)->get();

// Update
$user->name = 'Jane';
$user->save();

// Delete
$user->delete();
```

### Query Builder

```php
$users = User::where('status', 'active')
    ->where('age', '>', 18)
    ->orderBy('created_at', 'DESC')
    ->take(10)
    ->get();
```

### Relationships

| Attribute | Type |
|-----------|------|
| `#[OwnsOne]` | One-to-One |
| `#[OwnsMany]` | One-to-Many |
| `#[OwnedBy]` | Inverse One-to-One/Many |
| `#[OwnedByMany]` | Many-to-Many |
| `#[OwnsOnePoly]` | Polymorphic One-to-One |
| `#[OwnsManyPoly]` | Polymorphic One-to-Many |
| `#[OwnedByAny]` | Polymorphic Inverse |

```php
use Strux\Component\Database\ORM\Attributes\OwnsMany;
use Strux\Component\Database\ORM\Attributes\OwnedBy;

class Brand extends Model
{
    #[OwnsMany(Product::class, 'brandId', 'id')]
    public Collection $products;
}

class Product extends Model
{
    #[OwnedBy(Brand::class, 'brandId', 'id')]
    public ?Brand $brand;
}
```

### Additional ORM Features

- **Schema attributes** — `#[Index]`, `#[Unique]`, composite indexes on classes
- **Auto-migrations** — `php bin/console db:migrate` generates migrations by diffing attributes against the database
- **Model events** — `Saving`, `Saved`, `Creating`, `Created`, `Updating`, `Updated`, `Deleting`, `Deleted`, `Retrieved`
- **Soft deletes** — `use HasSoftDeletes`
- **Auto-validation** — `#[Validate]` rules on model properties
- **Query caching** — `->stashFor(60)` caches query results
- **Pagination** — `->paginate(15)`
- **Transactions** — `Model::transaction(fn() => ...)`
- **Entity builders** — generate test data
- **Ad-hoc queries** — query without a model using `DB::table('users')`

---

## Task Scheduler

Automate recurring tasks with PHP attributes. Tasks are discovered automatically by scanning `src/`.

```php
use Strux\Component\Scheduler\Attributes\Schedule;
use Strux\Component\Scheduler\Attributes\WithoutOverlapping;
use Strux\Component\Scheduler\Attributes\SendOutputTo;

#[Schedule(frequency: 'daily')]
#[WithoutOverlapping(expiresAfter: 30)]
#[SendOutputTo(filename: 'daily-report.log', append: true)]
class GenerateDailyReport
{
    public function handle(): void
    {
        echo "Generating report...\n";
    }
}
```

```bash
# Run once (for crontab: * * * * * cd /app && php bin/console schedule:run)
php bin/console schedule:run

# Daemon mode (runs continuously)
php bin/console schedule:work
```

Supported features: cron expressions, named frequencies (`everyminute`, `everyfiveminutes`, `daily`, etc.),
timezone support, mutex locking (`#[WithoutOverlapping]`), conditional execution (`#[RunWhen]`),
output capture (`#[SendOutputTo]`), events (`TaskStarting`, `TaskSuccess`, `TaskFailed`, `TaskSkipped`),
and maintenance mode awareness.

---

## Event Dispatcher

The framework includes a PSR-14 event dispatcher.

```php
use Strux\Component\Events\EventDispatcher;

class UserRegistered
{
    public function __construct(public readonly User $user) {}
}

// Dispatching
$events->dispatch(new UserRegistered($user));

// Listening
class SendWelcomeEmail
{
    public function handle(UserRegistered $event): void
    {
        // Send email to $event->user
    }
}

$events->addListener(UserRegistered::class, [SendWelcomeEmail::class, 'handle']);
```

---

## Queue System

Database-driven background job processing.

```php
use Strux\Component\Queue\QueueInterface;
use Strux\Component\Queue\ShouldQueue;

class SendEmailJob implements ShouldQueue
{
    public function __construct(private readonly User $user) {}
    public function handle(): void { /* ... */ }
}

// Push to queue
$queue->push(new SendEmailJob($user));
```

```bash
php bin/console queue:init     # Create queue tables
php bin/console queue:start     # Start queue worker
```

Scheduled tasks can also be pushed to the queue by implementing `ShouldQueue`.

---

## Auth System

Complete authentication with Session and JWT sentinels:

```php
// Login
if ($this->auth->authenticate($request->input('email'), $request->input('password'))) {
    return $this->redirect('/dashboard');
}

// Get current user
$user = $this->auth->user();

// Check permissions
$user->can('create_artworks');

// Protect routes
#[Middleware([AuthorizationMiddleware::class])]
```

Features: registration, login/logout, email verification, password recovery, "remember me",
roles & permissions (`#[Authorize('create_artworks')]`), route protection middleware,
and fine-grained policies.

---

## Validation

Attribute-driven validation on models and forms:

```php
$validator = new Validator($request->all());

$validator->add('email', [new Required(), new Email()]);
$validator->add('password', [new Required()]);

if ($validator->isValid()) {
	// Form is valid
} else {
	dump($validator->getErrors());
}
```

---

## CLI Commands

```bash
php bin/console                                    # List all commands

# Scaffolding
php bin/console new:controller Home                # Create a controller
php bin/console new:entity Product --domain=Catalog # Create a model
php bin/console new:job SendEmail                   # Create a queue job
php bin/console new:form ContactForm                # Create a form
php bin/console new:scheduled-task CleanupTemp      # Create a scheduled task
php bin/console new:module Auction                  # Scaffold a domain module
php bin/console new:middleware Auth                 # Create middleware
php bin/console new:registry Custom                # Create a service registry

# Database
php bin/console db:init                             # Create database tables
php bin/console db:migrate                          # Auto-generate migration
php bin/console db:upgrade                          # Run pending migrations
php bin/console db:rollback                         # Revert last migration
php bin/console db:seed                             # Run database seeder

# Scheduler
php bin/console schedule:run                        # Run due tasks once
php bin/console schedule:work                       # Start scheduler daemon

# Queue
php bin/console queue:init                          # Create queue tables
php bin/console queue:start                          # Start queue worker

# Other
php bin/console auth:init                           # Scaffold auth system
php bin/console session:init                        # Create session table
php bin/console run                                 # Start dev server on :8000
```

---

## Forms

Attribute-driven forms with auto-binding and built-in rendering:

```php
use Strux\Component\Form\Attributes\BooleanField;
use Strux\Component\Form\Attributes\ButtonField;
use Strux\Component\Form\Attributes\StringField;
use Strux\Component\Form\Attributes\TextAreaField;
use Strux\Component\Form\Attributes\URLField;
use Strux\Component\Form\Form;
use Strux\Component\Validation\Rules\Required;

class BrandForm extends Form
{
    #[StringField(label: 'Brand Name', rules: [
        'required',
        'alpha',
    ])]
    protected string $name;

    #[StringField(label: 'Slug', rules: ['required', 'slug'])]
    protected string $slug;

    #[TextAreaField(label: 'Description')]
    protected string $description;

    #[ButtonField(label: 'Save Brand')]
    protected string $submit;
}
```

Forms can bind to requests, models, or arrays, with auto-validation and Twig rendering.

---

## License

Strux Framework is open-source software licensed under the **MIT License**.
