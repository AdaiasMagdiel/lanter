<?php

function lanter_action_table_structure(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $views = $driver->listViews();
    $activeTable = $_GET['table'] ?? null;

    if ($activeTable === null || (!in_array($activeTable, $tables, true) && !in_array($activeTable, $views, true))) {
        lanter_redirect('dashboard');

        return;
    }

    $columns = $driver->describeTable($activeTable);

    $keyLabels = ['PRI' => 'PK', 'UNI' => 'UNIQUE', 'MUL' => 'INDEX'];

    $rows = '';
    foreach ($columns as $column) {
        $keyLabel = $keyLabels[$column['key']] ?? $column['key'];
        $keyBadge = $column['key'] !== ''
            ? '<span class="lanter-key">' . lanter_icon('key') . htmlspecialchars($keyLabel) . '</span>'
            : '';
        $nullable = $column['nullable']
            ? '<span class="lanter-badge lanter-badge-yes">YES</span>'
            : '<span class="lanter-badge">NO</span>';
        $default = $column['default'] ?? '';

        $rows .= '<tr>'
            . '<td>' . htmlspecialchars($column['name']) . '</td>'
            . '<td>' . htmlspecialchars($column['type']) . '</td>'
            . '<td>' . $nullable . '</td>'
            . '<td>' . htmlspecialchars((string) $default) . '</td>'
            . '<td>' . $keyBadge . '</td>'
            . '</tr>';
    }

    $content = <<<HTML
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th><th>Key</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
        HTML;

    lanter_render_layout('Structure: ' . $activeTable, $content, $tables, $activeTable, lanter_table_counts($driver, $tables), $views);
}
