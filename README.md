# Lanter

A single-file database manager for PHP, in the spirit of Adminer/phpMyAdmin.

Drop `dist/lanter.php` next to your app, point it at a database, and get a
web UI for browsing, editing and querying it — no dependencies, no install
step, no external assets.

## Features

- SQLite, MySQL/MariaDB and PostgreSQL through PDO
- Table and view listing; structure view with columns, indexes and foreign keys
- Data grid with paging, sorting, global search, per-column filters and bulk delete
- Foreign-key values link straight to the referenced row
- Row insert / update / delete with typed inputs (dates, booleans, enums, JSON)
- Schema changes from the UI: create tables and views, add / rename / drop columns, create / drop indexes, rename / truncate / drop tables
- Export as SQL (structure, data or both), CSV or JSON; import CSV rows or run an SQL script
- SQL console with multi-statement runs, history, result export and a guard before destructive statements
- Read-only connections, CSRF protection on every write, light and dark themes
- Zero dependencies: one PHP file, fonts and icons embedded

## Requirements

- PHP 8.1+
- The matching PDO extension: `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`

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
The connection is kept in the PHP session until you sign out. Tick **Open
read-only** on that screen to browse without any risk of changing data.

Every tagged release also attaches `lanter.php` as a download on the
[GitHub releases page](https://github.com/AdaiasMagdiel/lanter/releases).

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
    'per_page' => 25,         // default grid page size: 25, 50, 100, 250 or 500
    'sql_history' => 20,      // queries remembered per session in the SQL console (0 disables)
]);

require __DIR__ . '/lanter.php';
```

If a constant is awkward in your setup (see *Long-running workers* below),
a global variable with the same name and shape works too:

```php
$GLOBALS['LANTER_CONFIG'] = [ /* same keys */ ];
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

**PostgreSQL**

```php
[
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'port' => 5432,
    'database' => 'app',          // leave empty ('') to get a database picker
    'user' => 'postgres',
    'password' => 'secret',
]
```

Lanter works in the connection's current schema (`public` unless the
role's `search_path` says otherwise).

**Read-only**

Any connection accepts `'read_only' => true`. Lanter then hides every
write control, refuses inserts, updates, deletes and schema changes, and
only lets `SELECT`-style statements through the SQL console. Use it for
production databases you want to inspect, not touch:

```php
[
    'driver' => 'pgsql',
    'host' => 'db.internal',
    'database' => 'app',
    'user' => 'readonly',
    'password' => getenv('DB_RO_PASSWORD'),
    'read_only' => true,
]
```

The flag is enforced by Lanter, not by the database. Pair it with a
database user that only has `SELECT` grants when the stakes are high.

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
- The **Open read-only** checkbox sets `read_only` on the session's
  connection, so a careless query cannot change anything.

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
  remembered in the session until the next `?conn=`.
- **Sign out** only resets back to the default connection, since nothing
  stops the next request from reconnecting.
- Mark a connection `'read_only' => true` to expose it safely, for example
  a production replica next to the local dev database.
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
  databases for different users (e.g. a per-tenant database), or add
  `'read_only' => true` for everyone except administrators.
- Returning `null` answers with HTTP 403 and a short error page. If you
  would rather redirect to your own login page, do that in your
  middleware/route guard *before* Lanter runs and only call the delegate
  for already-authenticated users.
- Your app must have already started whatever it needs (session, auth
  guard, container) before the delegate is invoked. In a framework this is
  automatic when Lanter runs inside a controller.
- Lanter still uses the PHP session in this mode, but only for its own UI
  state (CSRF token, chosen database, SQL history). Credentials are never
  written to it.

## Security notes

- **CSRF.** Every form carries a per-session token and every POST is
  checked. A request without a valid token gets HTTP 419 and nothing runs.
  If you build your own links to Lanter actions, only `GET` pages can be
  linked; writes must go through Lanter's forms.
- **Read-only** is described above. It is enforced in PHP before any
  driver call, including each statement of a multi-statement SQL run.
- **Identifiers** typed into the UI (table, column, index names) are
  validated against `[A-Za-z_][A-Za-z0-9_]*` and always quoted. Column
  types from the table builder come from a fixed list, with a conservative
  allowlist for custom types such as `ENUM('a','b')`.
- **What Lanter does not do:** rate limiting, audit logs, or user
  management. The host application (or HTTP auth) owns *who* gets in;
  Lanter owns *what they can do once inside*.

## Integrating with a framework

Lanter is a plain PHP script: it reads `$_GET`, `$_POST`, `$_FILES`,
`$_SESSION` and `$_SERVER['REQUEST_URI']`, echoes HTML, and sends
`Location` headers for redirects. Embedding it means serving it from a
route that:

1. Checks the current user is allowed (your middleware/guard).
2. Defines `LANTER_CONFIG`, usually in `delegate` mode.
3. Requires `lanter.php`.
4. Returns whatever Lanter echoed as the response body.

Lanter builds all its links relative to the current URL (`?action=...`), so
it works at any path, e.g. `/admin/db`. Match `GET` and `POST` on that route.

### Erlenmeyer

[Erlenmeyer](https://github.com/AdaiasMagdiel/Erlenmeyer) is the framework
Lanter was built alongside, so the fit is direct. Erlenmeyer starts the
native PHP session when the `App` is created, which means Lanter and your
app share the same `$_SESSION`, and a route middleware is the natural place
for the access check.

```php
<?php

use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use AdaiasMagdiel\Erlenmeyer\Session;

require __DIR__ . '/vendor/autoload.php';

$app = new App();

$requireAdmin = function (Request $req, Response $res, callable $next, $params) {
    if (Session::get('role') !== 'admin') {
        $res->setStatusCode(403)->withHtml('Forbidden')->send();

        return;
    }

    $next($req, $res, $params);
};

$app->match(['GET', 'POST'], '/admin/db', function (Request $req, Response $res, $params) {
    define('LANTER_CONFIG', [
        'auth' => [
            'mode' => 'delegate',
            'delegate' => static fn (): array => [
                'driver' => 'sqlite',
                'path' => __DIR__ . '/storage/app.sqlite',
            ],
        ],
    ]);

    ob_start();
    require __DIR__ . '/tools/lanter.php';
    $body = ob_get_clean();

    if (($to = lanter_redirect_target()) !== null) {
        $res->redirect($to)->send();

        return;
    }

    $res->withHtml($body)->send();
}, [$requireAdmin]);

$app->run();
```

Notes:

- The middleware is the real gate. Because it runs before the handler, the
  delegate never sees an unauthenticated user and can simply return the
  connection.
- The delegate can read Erlenmeyer's `Session` too, for example to pick a
  per-user database.
- Erlenmeyer's `Response::send()` echoes the body, so capturing Lanter's
  output with `ob_start()` and handing it to `withHtml()` keeps status code
  and headers under Erlenmeyer's control.
- After a form submit Lanter wants to redirect. `lanter_redirect_target()`
  returns that URL (or `null`), so the route can answer with Erlenmeyer's
  own `redirect()` instead of a 200 page with a stray `Location` header.
- Erlenmeyer's `run()` turns PHP warnings into exceptions. If you ever see
  an `ErrorException` pointing into `lanter.php`, please report it as a bug.

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
    $body = ob_get_clean();

    if (($to = lanter_redirect_target()) !== null) {
        return redirect($to);
    }

    return response($body);
})->middleware(['auth', 'can:access-lanter']);
```

The `auth` and `can:` middleware do the access control, so the delegate only
has to map Laravel's connection config to Lanter's shape. Define the
`access-lanter` gate in `AuthServiceProvider` (or use any policy you prefer).

Notes:

- Lanter calls `session_start()` for its own needs. Laravel's session
  handler is independent, so both coexist.
- After a form submit Lanter wants to redirect; `lanter_redirect_target()`
  hands you the URL so you can return a proper Laravel redirect. Without
  that check Laravel would answer 200 and the browser would stay put.
- Exclude the route from Laravel's CSRF verification
  (`VerifyCsrfToken::$except` or `validateCsrfTokens(except: ['admin/db'])`
  in Laravel 11+). Lanter's forms carry their own token and check it.

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
        $body = ob_get_clean();

        if (($to = lanter_redirect_target()) !== null) {
            return $this->redirect($to);
        }

        return new Response($body);
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
    $body = ob_get_clean();

    if (($to = lanter_redirect_target()) !== null) {
        return $response->withHeader('Location', $to)->withStatus(302);
    }

    $response->getBody()->write($body);

    return $response;
})->add(new RequireAdminMiddleware());
```

### Limitations when embedding

- **`define()` is global.** Define `LANTER_CONFIG` inside the route that
  serves Lanter, not in a bootstrap file, so it does not clash with other
  constants or run on unrelated requests.
- **Output is echoed, not returned.** Wrap the `require` in `ob_start()` /
  `ob_get_clean()` when the framework expects a response object, as shown
  above.
- **Redirects need a hand.** Lanter sends a `Location` header through PHP's
  `header()`, but most frameworks then overwrite the status with 200. Check
  `lanter_redirect_target()` after the `require` and return your
  framework's redirect, as every example above does.
- **Downloads use PHP's `header()`.** Exports set `Content-Type` and
  `Content-Disposition` directly; returning the captured body as a normal
  response keeps them, though response middleware does not see them.

### Long-running workers

Laravel Octane, RoadRunner, FrankenPHP worker mode and Swoole keep one PHP
process alive across requests. `lanter.php` guards its declarations with
`function_exists`, so `require` on every request is safe, and it never
calls `exit`. Two adjustments are needed:

1. Use `$GLOBALS['LANTER_CONFIG'] = [...]` instead of `define()`, because a
   constant cannot be redefined on the second request.
2. Call `require` (not `require_once`) so `lanter_run()` executes each time.

```php
Route::match(['get', 'post'], '/admin/db', function () {
    $GLOBALS['LANTER_CONFIG'] = [
        'auth' => ['mode' => 'delegate', 'delegate' => static fn (): array => [/* ... */]],
    ];

    ob_start();
    require base_path('tools/lanter.php');
    $body = ob_get_clean();

    return ($to = lanter_redirect_target()) !== null ? redirect($to) : response($body);
});
```

This path is less travelled than PHP-FPM. If something misbehaves, fall
back to a classic FPM pool for the Lanter route and open an issue.

## Working with data

**Grid filters.** The *Filters* button opens one box per column. Each box
accepts a tiny grammar:

| You type | Meaning |
| --- | --- |
| `alice` | column contains `alice` (case-insensitive on PostgreSQL, collation-dependent elsewhere) |
| `= 42` | exact match |
| `!= 42`, `<> 42` | not equal |
| `> 5`, `>= 5`, `< 5`, `<= 5` | comparison |
| `null`, `!null` | is NULL / is not NULL |

Filters combine with AND, survive sorting and paging, and are plain query
parameters, so a filtered view can be bookmarked or shared. Clicking a
foreign-key value opens the referenced table with an `= value` filter
already applied.

**Export.** The *Export* menu on a table or view offers SQL (structure +
data, structure only, data only), CSV and JSON. Exports stream in chunks,
so large tables do not exhaust memory.

**Import.** The *Import* page (sidebar icon or button on a table) accepts a
CSV whose first line names the columns, or an SQL script. Both run inside a
transaction: a bad row or statement rolls everything back. Empty CSV cells
become `NULL`.

**SQL console.** Separate statements with `;`. Each one gets its own
result or error, and the run stops at the first failure. `SELECT` results
can be exported as CSV or JSON. The last queries are kept in the session
under *History*. Before running `DROP`, `TRUNCATE`, `ALTER` or an
unfiltered `DELETE`/`UPDATE`, the console asks for confirmation.

**Keyboard.** `/` focuses the table filter (or the grid search), `Esc`
closes modals, and `Ctrl`/`Cmd` + `Enter` runs the SQL console. The theme
toggle in the sidebar switches between dark and light and is remembered
per browser.

## Development

Source lives in `src/`, organized by responsibility (drivers, actions,
views). `build.php` concatenates and minifies it into the single
`dist/lanter.php` artifact:

```bash
php build.php
```

Tests use PHPUnit against an in-memory SQLite database and also check that
`dist/lanter.php` matches the sources, so remember to rebuild before
committing:

```bash
composer install
composer test
```

The same driver contract runs against real MySQL/MariaDB and PostgreSQL
servers when these variables are set (the tests are skipped otherwise, and
they create and drop `lt_*` tables in the given database):

```bash
LANTER_TEST_MYSQL="host=127.0.0.1;port=3306;database=lanter_test;user=root;password=secret" \
LANTER_TEST_PGSQL="host=127.0.0.1;port=5432;database=lanter_test;user=postgres;password=secret" \
composer test
```

CI runs the full suite, with both servers provisioned, on PHP 8.1 through
8.4. Pushing a tag that starts
with `v` additionally creates a GitHub release with `lanter.php` attached.
See [CHANGELOG.md](CHANGELOG.md) for what changed between versions.

### Adding a driver

Extend `Lanter_PdoDriver` (see `src/Driver/PgsqlDriver.php` for a compact
example), register the `driver` name in `lanter_make_driver()`,
`lanter_driver_kind()` and `lanter_driver_label()` in `src/bootstrap.php`,
add the file to `build.php` and `tests/bootstrap.php`, and extend
`lanter_table_builder_types()` with sensible column types.

## License

GPLv3 — see [LICENSE](LICENSE).
