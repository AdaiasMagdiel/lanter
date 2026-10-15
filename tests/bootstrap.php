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

$_SESSION = [];
