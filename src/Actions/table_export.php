<?php

function lanter_action_table_export(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $views = $driver->listViews();
    $table = $_GET['table'] ?? '';
    $isView = in_array($table, $views, true);

    if (!in_array($table, $tables, true) && !$isView) {
        lanter_redirect('dashboard');

        return;
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table . '.sql"');

    echo "-- Lanter export of " . $driver->quoteIdentifier($table) . "\n";
    echo "-- Generated " . date('Y-m-d H:i:s') . "\n\n";
    echo rtrim($driver->getCreateStatement($table, $isView), "; \t\n\r") . ";\n\n";

    if (!$isView) {
        $chunkSize = 500;
        $offset = 0;

        while (true) {
            $rows = $driver->fetchRows($table, $chunkSize, $offset);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $columnList = implode(', ', array_map($driver->quoteIdentifier(...), array_keys($row)));
                $valueList = implode(', ', array_map($driver->quoteValue(...), array_values($row)));

                echo 'INSERT INTO ' . $driver->quoteIdentifier($table) . ' (' . $columnList . ') VALUES (' . $valueList . ");\n";
            }

            if (count($rows) < $chunkSize) {
                break;
            }

            $offset += $chunkSize;
        }
    }

    exit;
}
