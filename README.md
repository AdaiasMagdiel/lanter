# Lanter

A single-file database manager for PHP, in the spirit of Adminer/phpMyAdmin.

Drop `dist/lanter.php` next to your app, point it at a database, and get a
web UI for browsing, editing and querying it — no dependencies, no install
step, no external assets.

## Features

- Table and view listing, structure inspection (columns, types, keys, defaults)
- Data grid with paging, search and bulk delete
- Row-level insert / update / delete
- Visual table builder and view creation form
- SQL console
- SQL export for tables and views
- SQLite and MySQL/MariaDB support (via PDO)

## Requirements

- PHP 8.1+
- The `pdo_sqlite` and/or `pdo_mysql` extensions, depending on which databases you want to open

## Getting started

Copy `dist/lanter.php` into your project and access it through your web
server:

```
your-project/
└── admin/
    └── lanter.php
```

Open `https://your-app/admin/lanter.php` in the browser. By default Lanter
shows a login screen where you pick a driver and enter connection details.
The connection is kept in the PHP session until you sign out.

## Configuration

All configuration goes through a single `LANTER_CONFIG` constant defined
*before* the file is required. Every key is optional; missing keys fall
back to the defaults shown below.

```php
<?php

define('LANTER_CONFIG', [
    'auth' => [
        'mode' => 'login',    // 'login' | 'none' | 'delegate'
        'delegate' => null,   // callable, only used by 'delegate' mode
    ],
    'connections' => [],      // named connections, used by 'none' mode
]);

require __DIR__ . '/lanter.php';
```

### Connection arrays

Wherever Lanter expects a connection (the `connections` list or the value
returned by a delegate), it is a plain array:

**SQLite**

```php
[
    'driver' => 'sqlite',
    'path' => __DIR__ . '/storage/database.sqlite',
]
```

**MySQL / MariaDB**

```php
[
    'driver' => 'mysql',          // or 'mariadb'
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'app',          // leave empty ('') to get a database picker
    'user' => 'root',
    'password' => 'secret',
]
```

## Authentication modes

Lanter does not have users or passwords of its own. The `auth.mode` setting
decides *where the database credentials come from* and *who is allowed in*.

| Mode | Credentials come from | Who can access | Typical use |
| --- | --- | --- | --- |
| `login` | Form filled in by the visitor | Anyone who reaches the URL and knows DB credentials | Standalone tool, local or behind HTTP auth |
| `none` | `connections` in config | Anyone who reaches the URL | Local development only |
| `delegate` | Your callback | Whoever your callback approves | Embedded in an app that already has auth |

### `login` (default)

Lanter renders its own sign-in form. The visitor chooses SQLite or
MySQL/MariaDB, types the connection details, and Lanter tries to connect.
On success the connection array is stored in `$_SESSION` and reused on
every request until **Sign out** is clicked.

```php
define('LANTER_CONFIG', [
    'auth' => ['mode' => 'login'],
]);
```

Things to know:

- The `connections` list is ignored in this mode.
- The form only protects against people who don't know your database
  credentials. On a public server, put the file behind HTTP basic auth, an
  IP allowlist, or a VPN.
- Credentials typed into the form live in the session for as long as the
  session does. Sign out to clear them.

### `none`

No login screen at all. Lanter connects straight to the first entry in
`connections` and shows the dashboard.

```php
define('LANTER_CONFIG', [
    'auth' => ['mode' => 'none'],
    'connections' => [
        'app' => [
            'driver' => 'sqlite',
            'path' => __DIR__ . '/../storage/app.sqlite',
        ],
        'analytics' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'analytics',
            'user' => 'root',
            'password' => '',
        ],
    ],
]);
```

Things to know:

- With several connections, the first one is used by default. Switch with
  the `conn` query parameter: `lanter.php?conn=analytics`. The choice is
  remembered in the session.
- **Sign out** only resets back to the default connection, since nothing
  stops the next request from reconnecting.
- If `connections` is empty, Lanter falls back to the login form.
- Anyone who can open the URL gets full read/write access to the database.
  Use this on `localhost` or inside Docker for development; never expose it
  publicly.

### `delegate`

You hand Lanter a callable. On every request Lanter calls it with no
arguments and expects either a connection array (user is allowed in) or
`null` (user is not allowed). Nothing is stored in the session by Lanter in
this mode; your application stays the source of truth.

```php
define('LANTER_CONFIG', [
    'auth' => [
        'mode' => 'delegate',
        'delegate' => static function (): ?array {
            if (!currentUserIsAdmin()) {
                return null;
            }

            return [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST'),
                'port' => (int) getenv('DB_PORT'),
                'database' => getenv('DB_DATABASE'),
                'user' => getenv('DB_USERNAME'),
                'password' => getenv('DB_PASSWORD'),
            ];
        },
    ],
]);
```

Things to know:

- The callback runs on every Lanter request, so keep it cheap (read your
  app's session or auth guard, don't hit an external API).
- Because the callback decides the connection, you can return different
  databases for different users (e.g. a per-tenant database).
- Returning `null` makes Lanter show its login form, but the form cannot
  grant access in this mode. For a cleaner experience, deny access in your
  own middleware/route guard *before* Lanter runs (403 or redirect to your
  login page) and only call the delegate for already-authenticated users.
- Your app must have already started whatever it needs (session, auth
  guard, container) before the delegate is invoked. In a framework this is
  automatic when Lanter runs inside a controller.

## Integrating with a framework

Lanter is a plain PHP script: it reads `$_GET`, `$_POST`, `$_SESSION` and
`$_SERVER['REQUEST_URI']`, echoes HTML, and calls `header()` + `exit` for
redirects. Embedding it means serving it from a route that:

1. Checks the current user is allowed (your middleware/guard).
2. Defines `LANTER_CONFIG`, usually in `delegate` mode.
3. Requires `lanter.php`.
4. Returns whatever Lanter echoed as the response body.

Lanter builds all its links relative to the current URL (`?action=...`), so
it works at any path, e.g. `/admin/db`. Match `GET` and `POST` on that route.

### Plain PHP (no framework)

```php
<?php
// admin/db.php
session_start();

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

define('LANTER_CONFIG', [
    'auth' => [
        'mode' => 'delegate',
        'delegate' => static fn (): array => [
            'driver' => 'sqlite',
            'path' => __DIR__ . '/../storage/app.sqlite',
        ],
    ],
]);

require __DIR__ . '/../vendor/lanter.php';
```

### Laravel

```php
// routes/web.php
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/admin/db', function () {
    $db = config('database.connections.' . config('database.default'));

    define('LANTER_CONFIG', [
        'auth' => [
            'mode' => 'delegate',
            'delegate' => static fn (): array => match ($db['driver']) {
                'sqlite' => [
                    'driver' => 'sqlite',
                    'path' => $db['database'],
                ],
                default => [
                    'driver' => 'mysql',
                    'host' => $db['host'],
                    'port' => (int) $db['port'],
                    'database' => $db['database'],
                    'user' => $db['username'],
                    'password' => $db['password'],
                ],
            },
        ],
    ]);

    ob_start();
    require base_path('tools/lanter.php');

    return response(ob_get_clean());
})->middleware(['auth', 'can:access-lanter']);
```

The `auth` and `can:` middleware do the access control, so the delegate only
has to map Laravel's connection config to Lanter's shape. Define the
`access-lanter` gate in `AuthServiceProvider` (or use any policy you prefer).

Notes:

- Lanter calls `session_start()` for its own needs. Laravel's session
  handler is independent, so both coexist.
- Redirects inside Lanter use `header()` + `exit`, which skips Laravel's
  terminating middleware for that request. This is harmless for an admin
  tool, but be aware of it if you log every response.
- Exclude the route from CSRF verification (`VerifyCsrfToken::$except` or
  `validateCsrfTokens(except: ['admin/db'])` in Laravel 11+), since Lanter's
  forms do not carry a Laravel token.

### Symfony

```php
// src/Controller/LanterController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class LanterController extends AbstractController
{
    #[Route('/admin/db', name: 'admin_db', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): Response
    {
        define('LANTER_CONFIG', [
            'auth' => [
                'mode' => 'delegate',
                'delegate' => static fn (): array => [
                    'driver' => 'mysql',
                    'host' => $_ENV['DB_HOST'],
                    'port' => (int) $_ENV['DB_PORT'],
                    'database' => $_ENV['DB_NAME'],
                    'user' => $_ENV['DB_USER'],
                    'password' => $_ENV['DB_PASSWORD'],
                ],
            ],
        ]);

        ob_start();
        require $this->getParameter('kernel.project_dir') . '/tools/lanter.php';

        return new Response(ob_get_clean());
    }
}
```

Disable CSRF for this route if your firewall enforces it on forms, and keep
`#[IsGranted]` (or an `access_control` rule) as the real gate.

### Slim / any PSR-7 framework

```php
$app->map(['GET', 'POST'], '/admin/db', function ($request, $response) {
    define('LANTER_CONFIG', [
        'auth' => [
            'mode' => 'delegate',
            'delegate' => static fn (): array => [
                'driver' => 'sqlite',
                'path' => __DIR__ . '/../var/app.sqlite',
            ],
        ],
    ]);

    ob_start();
    require __DIR__ . '/../tools/lanter.php';

    $response->getBody()->write(ob_get_clean());

    return $response;
})->add(new RequireAdminMiddleware());
```

### Limitations when embedding

- **One request per process.** `lanter.php` declares functions and classes
  at the top level and runs immediately, so it cannot be required twice in
  the same PHP process. This rules out long-running workers (Laravel Octane,
  RoadRunner, FrankenPHP worker mode, Swoole) for the Lanter route. Serve it
  through classic PHP-FPM or `php -S` instead.
- **`define()` is global.** Define `LANTER_CONFIG` inside the route that
  serves Lanter, not in a bootstrap file, so it does not clash with other
  constants or run on unrelated requests.
- **Output is echoed, not returned.** Wrap the `require` in `ob_start()` /
  `ob_get_clean()` when the framework expects a response object, as shown
  above.

## Building from source

Source lives in `src/`, organized by responsibility (drivers, actions,
views). `build.php` concatenates and minifies it into the single
`dist/lanter.php` artifact:

```
php build.php
```

The generated file has zero external dependencies and can be copied
anywhere on its own.

## License

GPLv3 — see [LICENSE](LICENSE).
