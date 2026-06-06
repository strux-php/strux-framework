# Strux Framework

Strux is a modern, lightweight, and powerful **PHP framework** designed for building robust web applications and APIs.
It combines a clean architecture with a rich feature set—including an Active Record ORM, built-in queue system, event
dispatcher, and flexible middleware—while maintaining a minimal core with **few external dependencies**.

Strux strictly adheres to **PSR-1, PSR-2, PSR-3, PSR-4, and PSR-7** standards for maximum interoperability.

---

## 📋 Table of Contents

* [Features](#features)
* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Directory Structure](#directory-structure)
* [Routing](#routing)
* [Controllers](#controllers)
* [Requests & Responses](#requests--responses)
* [Middleware](#middleware)
* [Views & Templating](#views--templating)
* [Database & ORM](#database--orm)
* [Migrations](#migrations)
* [Query Builder](#query-builder)
* [ORM Relationships](#model-relationships)
* [Event Dispatcher](#event-dispatcher)
* [Queue System](#queue-system)
* [Security](#security)
* [Command-Line Interface (CLI)](#command-line-interface-cli)
* [License](#license)

---

## ✨ Features

* PSR-compliant architecture (PSR-1, 2, 3, 4, 7)
* Zero external dependencies
* Attribute-based routing and ORM
* Active Record ORM with relationships
* Middleware dispatcher
* Event & queue systems
* Built-in validation and security
* CLI tooling for rapid development
* Plates templating (Twig adapter available)

---

## 🧰 Requirements

* PHP **8.2+**
* Composer
* PDO extension (for database access)

---

## 🚀 Installation

### Create a New Project

```bash
composer create-project strux/strux-app my-app
cd my-app
```

### Serve the Application

```bash
php bin/console run
```

---

## ⚙️ Configuration

Configuration files are stored in the `etc/` directory and are automatically loaded.

### Environment Variables

Copy the example environment file and update it:

```bash
cp .env.example .env
```

```env
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=strux_db
DB_USERNAME=root
DB_PASSWORD=secret
```

---

## 📂 Directory Structure

```text
bin/        # CLI entry point
etc/        # Configuration & route files
src/        # Application source code
templates/  # Views, assets, language files
web/        # Public entry point (index.php)
var/        # Cache, logs, sessions
```

---

## 🛣 Routing

Routes are defined in `etc/routes/web.php` or `etc/routes/api.php`.

### Fluent Routing

```php
$router->get('/', [HomeController::class, 'index']);
$router->get('/users/:id', [UserController::class, 'show']);
$router->post('/login', [AuthController::class, 'login'])->name('login');
```

### Attribute-Based Routing

```php
use Strux\Component\Attributes\Route;

class UserController
{
    #[Route('/users/:id', methods: ['GET'])]
    public function show(int $id) {}
}
```

---

## 🎮 Controllers

Controllers live in `src/Http/Controller` and receive dependencies automatically via the service container.

```php
class PageController extends Controller
{
    public function home(Request $request)
    {
        return $this->view('home', ['name' => 'Kernel']);
    }
}
```

---

## 📥 Requests & Responses

### Request Access

```php
$request->input('name');
$request->safe()->input('name'); // Sanitized
$request->query('page');
$request->header('User-Agent');
$request->file('avatar');
```

### Responses

```php
return $this->view('profile');
return $this->json(['status' => 'ok']);
return $this->redirect('/login');
```

---

## 🛡 Middleware

Middleware intercepts requests before controllers execute.

```php
class AuthMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request, 
        RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Auth::check()) {
            return $this->responseFactory->createResponse(302)
            ->withHeader('Location', '/login');
        }
        return $handler->handle($request);
    }
}
```

* Global middleware: `etc/middleware.php`
* Route-specific or attribute-based registration supported

---

## 🎨 Views & Templating

Strux uses **Plates** by default (Twig supported via adapter).

```php
return $this->view('auth/login', ['error' => 'Invalid credentials']);
```

```php
<?php $this->layout('layouts/app', ['title' => 'Login']) ?>
<h1>Login</h1>
```

---

## 🗄 Database & ORM

Strux includes an **Active Record ORM** built from the ground up using modern PHP Attributes. Think of the ORM (Object-Relational Mapper) as a translator between your raw database tables and your clean PHP code. 

### ORM Definition

You define your database tables simply by creating PHP classes and adding "Attributes" (the `#[...]` syntax) above your properties.

```php
use Strux\Component\Database\Schema\Attributes\Table;
use Strux\Component\Database\Schema\Attributes\Column;
use Strux\Component\Database\Schema\Attributes\Id;
use Strux\Component\Database\Schema\Attributes\Index;
use Strux\Component\Database\Schema\Attributes\Unique;
use Strux\Component\Database\ORM\Model;

#[Table('users')]
class User extends Model
{
    #[Id]
    #[Column]
    public int $id;

    #[Column]
    public string $username;
}
```

> [!NOTE]
> When you run database migrations, the framework automatically scans your code for these attributes and builds your database tables for you! You rarely have to write raw SQL.

### Basic Usage

```php
// Creating a new record
$user = new User();
$user->username = 'john_doe';
$user->save();

// Finding and deleting a record
$user = User::find(1);
$user->delete();
```

### ⚡ Database Indexing (Advanced Dialect-Agnostic Support)

Indexes are like the index at the back of a large encyclopedia. Without an index, if you want to find every page that mentions "Apples", you have to read the entire book from start to finish. With an index, you just look up "Apples" and go straight to the correct pages. 

In databases, indexes speed up your queries significantly. Strux provides a powerful, **dialect-agnostic** indexing system. This means whether you are using MySQL, PostgreSQL, SQLite, or SQL Server, Strux knows the exact right grammar to safely create and destroy your indexes!

#### Single-Column Indexes
If you frequently search for users by their `status` or `role`, you should index that column. You can do this by simply adding the `#[Index]` attribute directly to the property in your class.

```php
    #[Column]
    #[Index]
    public string $status;
```
> [!TIP]
> The framework will automatically name this index something like `users_status_idx`. If you want to give it a custom name for strict database administration policies, you can do: `#[Index(name: 'my_custom_status_idx')]`.

#### Unique Indexes
If you want to ensure that two users can never have the same email address, you use a Unique Index. This enforces strict data integrity at the database level. Attempting to save a duplicate will throw a database error.

```php
    #[Column]
    #[Unique] // Or alternatively: #[Index(unique: true)]
    public string $email;
```

#### Composite Indexes (Multi-Column)
Sometimes you query the database using multiple columns at the exact same time. For example, finding a user by checking their `firstname` AND their `lastname`. For this, you want a Composite Index to maximize performance. 

Because a composite index involves multiple columns acting together, you apply this attribute to the **Class** itself, not an individual property.

```php
#[Table('users')]
#[Index(columns: ['firstname', 'lastname'], name: 'idx_user_name')]
class User extends Model
{
    // ...
}
```

> [!WARNING]
> Order matters incredibly in composite indexes! An index defined on `['firstname', 'lastname']` will drastically speed up queries searching by *both* names, OR queries searching by *just* `firstname`. However, it will **NOT** help queries searching by just `lastname`. Always order your most-queried columns first.

---

## 🏗 Migrations

Database migrations are like "version control" (like Git) for your database structure. They allow you to define your tables in code and share them easily with your team.

### Auto-Generating Migrations
Strux is incredibly smart. It compares the PHP `#[Column]` and `#[Index]` attributes in your code against your actual live database. If it notices you added a new index or a new column, it will automatically generate a migration file for you containing the exact SQL queries needed to update the database!

```bash
# Analyze code and automatically generate SQL differences
php bin/console db:migrate

# Apply the newly generated differences to the database
php bin/console db:upgrade
```

> [!IMPORTANT]
> The auto-generated migration files also safely include `down()` methods that tell the database exactly how to reverse the changes if you make a mistake and need to rollback. Thanks to our dialect-agnostic engine, the rollback logic perfectly handles tearing down tables, dropping columns, and safely removing composite indexes whether you're on MySQL, Postgres, SQLite, or SQL Server!

---

## 🔍 Query Builder

Under the hood, the ORM relies on a fluent Query Builder you can use for complex data retrieval.

```php
$users = User::query()
    ->where('active', 1)
    ->where('age', '>', 18)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

---

## 🔗 ORM Relationships

Supported relationships:

* `#[HasOne]`
* `#[HasMany]`
* `#[BelongsTo]`
* `#[BelongsToMany]`

```php
use Strux\Component\Database\ORM\Attributes\BelongsToMany;

class Student extends Model 
{
    #[BelongsToMany(related: Course::class, pivotTable: 'enrollments')]
    public Collection $courses;
}
```

```php
$student->courses; // Fetch courses
$student->courses()->sync([1, 2, 3]); // Synchronize pivot table relationships
```

---

## 📡 Event Dispatcher

```php
Event::dispatch(new UserRegistered($user));
```

```php
class SendWelcomeEmail
{
    public function handle(UserRegistered $event) {}
}
```

---

## 📨 Queue System

```php
Queue::push(new SendEmailJob($user));
```
Start the worker:
```bash
php bin/console queue:start
```

---

## 🔒 Security

* Authentication via Sentinels (Session, JWT)
* Authorization with `#[Authorize]` attributes
* CSRF protection middleware
* Built-in validation system (`Required`, `Email`, `MinLength`, etc.)

---

## 💻 Command-Line Interface (CLI)

```bash
php bin/console
php bin/console new:controller
php bin/console new:model
php bin/console db:seed
```

---

## 📄 License

Strux Framework is open-source software licensed under the **MIT License**.
