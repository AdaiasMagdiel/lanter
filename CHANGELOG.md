# Changelog

All notable changes to Lanter are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.1] - 2026-10-15

### Added

- Driver contract tests that run against live MySQL/MariaDB and PostgreSQL servers when `LANTER_TEST_MYSQL` / `LANTER_TEST_PGSQL` are set; CI now provisions both.

### Fixed

- SQL import on MySQL failed with "no active transaction" after a DDL statement, because MySQL auto-commits DDL. `commit()` now tolerates an already-ended transaction.
- Editing a row no longer tries to write an empty primary key when the field is missing from the form.

## [0.2.0] - 2026-10-15

### Added

- PostgreSQL driver (`driver: 'pgsql'`), including the login form and table builder types.
- CSRF protection on every form and POST action; requests without a valid token get HTTP 419.
- Read-only connections (`'read_only' => true`, or the "Open read-only" checkbox on the login screen). Writes, schema changes and non-SELECT SQL are refused.
- Structure tab: indexes and foreign keys listed; add, rename and drop columns; create and drop indexes; rename, truncate and drop tables; drop views.
- Foreign-key columns in the data grid link to the referenced row.
- Per-column filters with a small grammar (`contains`, `= x`, `> 5`, `null`, `!null`) and a configurable page size (`per_page`, plus a selector in the toolbar).
- Export as SQL (structure, data or both), CSV or JSON; import CSV into a table or run an SQL script, both inside a transaction.
- SQL console: multiple statements per run, per-session history (`sql_history`), export of a SELECT result as CSV or JSON, and a confirmation before destructive statements.
- Long cell values open in a modal with pretty-printed JSON and a copy button; `NULL` is shown distinctly.
- Light theme toggle (remembered per browser) and the `/` shortcut to focus the table filter or search.
- `LANTER_VERSION` constant, shown on the login screen and sidebar.
- `$GLOBALS['LANTER_CONFIG']` as an alternative to the constant, and a `function_exists` guard around the built file, so it can be included once per request in long-running workers.
- PHPUnit test suite, GitHub Actions CI (lint, tests, dist sync check) and a release job that attaches `dist/lanter.php` to tags.

### Changed

- Delegate mode returning `null` now answers HTTP 403 instead of showing a login form that could not grant access.
- In `none` mode `?conn=name` now switches the connection even when one is already in the session.
- Redirects and downloads no longer call `exit`; the request ends through an internal exception, so host frameworks regain control.
- Drivers share a PDO base class; SQLite now reports `UNIQUE`/`INDEX` keys in the structure view and includes indexes in SQL exports.

### Removed

- The unused `allow_ui_connections` and `data_file` config keys.

## [0.1.0] - 2026-10-15

First published single-file release: table and view browsing, data grid with search, sorting and bulk delete, row forms, visual table builder, view creation, SQL console and SQL export for SQLite and MySQL/MariaDB.

[Unreleased]: https://github.com/AdaiasMagdiel/lanter/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/AdaiasMagdiel/lanter/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/AdaiasMagdiel/lanter/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/AdaiasMagdiel/lanter/releases/tag/v0.1.0
