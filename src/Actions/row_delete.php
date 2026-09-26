<?php

function lanter_action_row_delete(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $table = $_POST['table'] ?? '';
    $pkValue = $_POST['pk'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($table, $tables, true) || $pkValue === null) {
        lanter_redirect('dashboard');

        return;
    }

    $columns = $driver->describeTable($table);
    $primaryKey = lanter_primary_key($columns);

    if ($primaryKey !== null) {
        $driver->deleteRow($table, $primaryKey, $pkValue);
    }

    lanter_redirect('dashboard', ['table' => $table]);
}
