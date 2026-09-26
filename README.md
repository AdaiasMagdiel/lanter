# Lanter

A single-file database manager for PHP, in the spirit of Adminer/phpMyAdmin.

Drop `dist/lanter.php` next to your app, point it at a database, and get a
web UI for browsing, editing and querying it — no dependencies, no install
step, no external assets.

## Features (MVP)

- Table listing, structure inspection (columns, types, keys, defaults)
- Data grid with paging and search
- Row-level insert / update / delete
- SQL console
- SQLite and MySQL/MariaDB support (via PDO)

## Getting started

Copy `dist/lanter.php` into your project and access it through your web
server:

```
your-project/
└── admin/
    └── lanter.php
```

By default it shows a login screen where you pick a driver and enter
connection details for the session.

## Configuration

To embed Lanter without its own login screen, define `LANTER_CONFIG` as a
constant *before* requiring the file:

```php
<?php

define('LANTER_CONFIG', [
    'auth' => [
        'mode' => 'none', // 'login' (default) | 'none' | 'delegate'
    ],
    'connections' => [
        'main' => [
            'driver' => 'sqlite',
            'path' => __DIR__ . '/database.sqlite',
        ],
    ],
]);

require __DIR__ . '/lanter.php';
```

Authentication modes:

- **`login`** (default) — Lanter's own login screen; connection lives in the
  session. Use this for standalone or exposed setups.
- **`none`** — connects directly using the fixed connection(s) from config.
  Local/dev use only.
- **`delegate`** — a callback you provide checks whether the current user is
  already authenticated in your host application and returns the connection
  details. Useful when embedding Lanter inside an existing admin panel.

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
