<?php

/** Thrown to stop the request after a redirect or a streamed download, instead of exit(). */
final class Lanter_Halt extends RuntimeException
{
}

function lanter_make_driver(array $connection): Lanter_DriverInterface
{
    $driver = match ($connection['driver'] ?? 'sqlite') {
        'mysql', 'mariadb' => new Lanter_MysqlDriver(),
        'pgsql', 'postgres', 'postgresql' => new Lanter_PgsqlDriver(),
        default => new Lanter_SqliteDriver(),
    };

    $driver->connect($connection);

    return $driver;
}

function lanter_driver_kind(array $connection): string
{
    return match ($connection['driver'] ?? 'sqlite') {
        'mysql', 'mariadb' => 'mysql',
        'pgsql', 'postgres', 'postgresql' => 'pgsql',
        default => 'sqlite',
    };
}

function lanter_driver_label(array $connection): string
{
    return match ($connection['driver'] ?? 'sqlite') {
        'mariadb' => 'MariaDB',
        'mysql' => 'MySQL',
        'pgsql', 'postgres', 'postgresql' => 'PostgreSQL',
        default => 'SQLite',
    };
}

/** Holds the resolved connection for the request so views don't depend on the session. */
function lanter_current_connection(?array $connection = null, bool $set = false): ?array
{
    static $current = null;

    if ($set) {
        $current = $connection;
    }

    return $current;
}

function lanter_is_read_only(?array $connection = null): bool
{
    $connection ??= lanter_current_connection();

    return (bool) ($connection['read_only'] ?? false);
}

function lanter_assert_writable(?array $connection = null): void
{
    if (lanter_is_read_only($connection)) {
        throw new RuntimeException('This connection is read-only.');
    }
}

function lanter_csrf_token(): string
{
    if (empty($_SESSION['lanter_csrf'])) {
        $_SESSION['lanter_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['lanter_csrf'];
}

function lanter_csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . htmlspecialchars(lanter_csrf_token()) . '">';
}

function lanter_csrf_is_valid(): bool
{
    $sent = $_POST['_token'] ?? $_SERVER['HTTP_X_LANTER_TOKEN'] ?? '';

    return is_string($sent) && $sent !== '' && hash_equals(lanter_csrf_token(), $sent);
}

function lanter_redirect(string $action, array $params = []): never
{
    $query = http_build_query(['action' => $action, ...$params]);
    $target = strtok($_SERVER['REQUEST_URI'], '?') . '?' . $query;

    lanter_redirect_target($target, true);
    header('Location: ' . $target, true, 302);

    throw new Lanter_Halt();
}

/** Where the last lanter_run() wanted to redirect, so host frameworks can emit their own redirect response. */
function lanter_redirect_target(?string $target = null, bool $set = false): ?string
{
    static $current = null;

    if ($set) {
        $current = $target;
    }

    return $current;
}

/** @return array<string, int> */
function lanter_table_counts(Lanter_DriverInterface $driver, array $tables): array
{
    $counts = [];

    foreach ($tables as $table) {
        try {
            $counts[$table] = $driver->countRows($table);
        } catch (Throwable $e) {
            // Views or exotic tables may not support COUNT(*); skip silently.
        }
    }

    return $counts;
}

function lanter_format_count(int $count): string
{
    if ($count >= 1_000_000) {
        return round($count / 1_000_000, 1) . 'M';
    }

    if ($count >= 1_000) {
        return round($count / 1_000, 1) . 'k';
    }

    return (string) $count;
}

/** @param array<int, array{name: string, type: string, nullable: bool, default: ?string, key: string}> $columns */
function lanter_primary_key(array $columns): ?string
{
    foreach ($columns as $column) {
        if ($column['key'] === 'PRI') {
            return $column['name'];
        }
    }

    return null;
}

function lanter_is_valid_identifier(string $name): bool
{
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
}

/** Splits a script into statements, honouring quotes and comments so semicolons inside them are kept. */
function lanter_split_sql(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($quote !== null) {
            $current .= $char;

            if ($char === '\\' && $quote !== '`' && $quote !== '"') {
                $current .= $next;
                $i++;
            } elseif ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($char === '-' && $next === '-') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $length : $end;
            $current .= "\n";

            continue;
        }

        if ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $length : $end + 1;

            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;

            continue;
        }

        if ($char === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }

            $current = '';

            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

function lanter_sql_is_read_only(string $statement): bool
{
    return preg_match('/^\s*(select|with|explain|show|describe|desc|pragma|values)\b/i', $statement) === 1
        && preg_match('/\b(insert|update|delete|drop|alter|create|truncate|replace|attach|vacuum|reindex)\b/i', $statement) !== 1;
}

function lanter_run(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $config = Lanter_Config::resolve();
    $auth = new Lanter_Auth($config);
    $connection = $auth->resolveConnection();
    lanter_current_connection($connection, true);
    lanter_redirect_target(null, true);

    $action = $_GET['action'] ?? 'dashboard';

    $actions = [
        'login' => 'lanter_action_login',
        'logout' => 'lanter_action_logout',
        'dashboard' => 'lanter_action_dashboard',
        'change_database' => 'lanter_action_change_database',
        'table_create' => 'lanter_action_table_create',
        'table_alter' => 'lanter_action_table_alter',
        'view_create' => 'lanter_action_view_create',
        'table_export' => 'lanter_action_table_export',
        'table_import' => 'lanter_action_table_import',
        'row_bulk_delete' => 'lanter_action_row_bulk_delete',
        'table_structure' => 'lanter_action_table_structure',
        'row_new' => 'lanter_action_row_new',
        'row_edit' => 'lanter_action_row_edit',
        'row_delete' => 'lanter_action_row_delete',
        'sql' => 'lanter_action_sql',
        'sql_export' => 'lanter_action_sql_export',
    ];

    $handler = $actions[$action] ?? 'lanter_action_dashboard';

    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !lanter_csrf_is_valid()) {
            http_response_code(419);
            lanter_render_error('The form token is invalid or expired. Go back and try again.', ['Back to dashboard' => '?action=dashboard']);

            return;
        }

        if ($connection === null) {
            if ($auth->deniesWhenUnresolved()) {
                http_response_code(403);
                lanter_render_error('You are not allowed to access this database.');

                return;
            }

            // Only the login form is reachable without a connection.
            lanter_action_login($config, $auth, null);

            return;
        }

        $handler($config, $auth, $connection);
    } catch (Lanter_Halt) {
        return;
    } catch (Throwable $e) {
        $links = ['Back to dashboard' => '?action=dashboard'];

        if ($connection !== null && lanter_make_driver_supports_databases($connection)) {
            $links['Switch database'] = '?action=change_database';
        }

        $links['Sign out'] = '?action=logout';

        lanter_render_error($e->getMessage(), $links);
    }
}

function lanter_make_driver_supports_databases(array $connection): bool
{
    return lanter_driver_kind($connection) !== 'sqlite';
}
