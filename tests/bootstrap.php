<?php

// Loads src/ in the same order as build.php so the tests exercise the real code paths.
$root = dirname(__DIR__);

foreach ([
    'src/Driver/DriverInterface.php',
    'src/Driver/PdoDriver.php',
    'src/Driver/SqliteDriver.php',
    'src/Driver/MysqlDriver.php',
    'src/Driver/PgsqlDriver.php',
    'src/Config.php',
    'src/Auth.php',
    'src/Types.php',
    'src/View/icons.php',
    'src/View/layout.php',
    'src/View/error.php',
    'src/View/css.php',
    'src/Actions/login.php',
    'src/Actions/logout.php',
    'src/Actions/dashboard.php',
    'src/Actions/table_create.php',
    'src/Actions/table_alter.php',
    'src/Actions/view_create.php',
    'src/Actions/table_export.php',
    'src/Actions/table_import.php',
    'src/Actions/table_structure.php',
    'src/Actions/row_form.php',
    'src/Actions/row_delete.php',
    'src/Actions/sql.php',
    'src/bootstrap.php',
] as $file) {
    require_once $root . '/' . $file;
}

require_once __DIR__ . '/DriverContractTestCase.php';

$_SESSION = [];

/** Parses "host=..;port=..;database=..;user=..;password=.." from the environment into a Lanter connection. */
function lanter_test_connection_from_env(string $variable, string $driver): ?array
{
    $raw = getenv($variable);

    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $connection = ['driver' => $driver];

    foreach (explode(';', $raw) as $pair) {
        if (!str_contains($pair, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $pair, 2);
        $connection[trim($key)] = trim($value);
    }

    $connection['port'] = (int) ($connection['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));

    return $connection;
}
