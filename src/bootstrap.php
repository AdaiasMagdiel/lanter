<?php

function lanter_make_driver(array $connection): Lanter_DriverInterface
{
    $driver = match ($connection['driver'] ?? 'sqlite') {
        'mysql', 'mariadb' => new Lanter_MysqlDriver(),
        default => new Lanter_SqliteDriver(),
    };

    $driver->connect($connection);

    return $driver;
}

function lanter_redirect(string $action, array $params = []): void
{
    $query = http_build_query(['action' => $action, ...$params]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . $query);
    exit;
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

function lanter_run(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $config = Lanter_Config::resolve();
    $auth = new Lanter_Auth($config);
    $connection = $auth->resolveConnection();

    $action = $_GET['action'] ?? 'dashboard';

    $actions = [
        'login' => 'lanter_action_login',
        'logout' => 'lanter_action_logout',
        'dashboard' => 'lanter_action_dashboard',
        'change_database' => 'lanter_action_change_database',
        'table_create' => 'lanter_action_table_create',
        'view_create' => 'lanter_action_view_create',
        'table_export' => 'lanter_action_table_export',
        'row_bulk_delete' => 'lanter_action_row_bulk_delete',
        'table_structure' => 'lanter_action_table_structure',
        'row_new' => 'lanter_action_row_new',
        'row_edit' => 'lanter_action_row_edit',
        'row_delete' => 'lanter_action_row_delete',
        'sql' => 'lanter_action_sql',
    ];

    $handler = $actions[$action] ?? 'lanter_action_dashboard';

    // Actions that don't require an active connection yet.
    $publicActions = ['login'];

    if ($connection === null && !in_array($action, $publicActions, true)) {
        lanter_action_login($config, $auth, null);

        return;
    }

    try {
        $handler($config, $auth, $connection);
    } catch (Throwable $e) {
        $actions = ['Back to dashboard' => '?action=dashboard'];

        if ($connection !== null && ($connection['driver'] ?? 'sqlite') !== 'sqlite') {
            $actions['Switch database'] = '?action=change_database';
        }

        $actions['Sign out'] = '?action=logout';

        lanter_render_error($e->getMessage(), $actions);
    }
}
