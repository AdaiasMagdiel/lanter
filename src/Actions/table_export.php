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
    }

    $format = $_GET['format'] ?? 'sql';
    $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $table);

    if (ini_get('zlib.output_compression')) {
        ini_set('zlib.output_compression', '0');
    }

    match ($format) {
        'csv' => lanter_export_csv($driver, $table, $safeName),
        'json' => lanter_export_json($driver, $table, $safeName),
        default => lanter_export_sql($driver, $table, $safeName, $isView, ($_GET['data'] ?? '1') !== '0', ($_GET['structure'] ?? '1') !== '0'),
    };

    throw new Lanter_Halt();
}

/** @return Generator<int, array<string, mixed>> */
function lanter_iterate_rows(Lanter_DriverInterface $driver, string $table): Generator
{
    $chunkSize = 500;
    $offset = 0;

    while (true) {
        $rows = $driver->fetchRows($table, $chunkSize, $offset);

        foreach ($rows as $row) {
            yield $row;
        }

        if (count($rows) < $chunkSize) {
            return;
        }

        $offset += $chunkSize;
    }
}

function lanter_export_sql(Lanter_DriverInterface $driver, string $table, string $safeName, bool $isView, bool $withData, bool $withStructure): void
{
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '.sql"');

    echo '-- Lanter ' . LANTER_VERSION . ' export of ' . $driver->quoteIdentifier($table) . "\n";
    echo '-- Generated ' . date('Y-m-d H:i:s') . "\n\n";

    if ($withStructure) {
        echo rtrim($driver->getCreateStatement($table, $isView), "; \t\n\r") . ";\n\n";
    }

    if ($isView || !$withData) {
        return;
    }

    $quotedTable = $driver->quoteIdentifier($table);

    foreach (lanter_iterate_rows($driver, $table) as $row) {
        $columnList = implode(', ', array_map($driver->quoteIdentifier(...), array_keys($row)));
        $valueList = implode(', ', array_map($driver->quoteValue(...), array_values($row)));

        echo 'INSERT INTO ' . $quotedTable . ' (' . $columnList . ') VALUES (' . $valueList . ");\n";
    }
}

function lanter_export_csv(Lanter_DriverInterface $driver, string $table, string $safeName): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '.csv"');

    $out = fopen('php://output', 'w');
    $headerWritten = false;

    foreach (lanter_iterate_rows($driver, $table) as $row) {
        if (!$headerWritten) {
            fputcsv($out, array_keys($row), ',', '"', '\\', "\n");
            $headerWritten = true;
        }

        fputcsv($out, array_map(static fn ($v): string => $v === null ? '' : (string) $v, $row), ',', '"', '\\', "\n");
    }

    if (!$headerWritten) {
        fputcsv($out, array_column($driver->describeTable($table), 'name'), ',', '"', '\\', "\n");
    }

    fclose($out);
}

function lanter_export_json(Lanter_DriverInterface $driver, string $table, string $safeName): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '.json"');

    echo "[\n";
    $first = true;

    foreach (lanter_iterate_rows($driver, $table) as $row) {
        echo ($first ? '' : ",\n") . '  ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $first = false;
    }

    echo "\n]\n";
}
