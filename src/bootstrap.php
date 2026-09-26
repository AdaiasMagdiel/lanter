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

function lanter_redirect(string $action): void
{
    $query = $action === '' ? '' : '?action=' . $action;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $query);
    exit;
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
        'table_structure' => 'lanter_action_table_structure',
    ];

    $handler = $actions[$action] ?? 'lanter_action_dashboard';

    // Actions that don't require an active connection yet.
    $publicActions = ['login'];

    if ($connection === null && !in_array($action, $publicActions, true)) {
        lanter_action_login($config, $auth, null);

        return;
    }

    $handler($config, $auth, $connection);
}
