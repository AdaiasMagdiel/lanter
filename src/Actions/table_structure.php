<?php

function lanter_action_table_structure(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $activeTable = $_GET['table'] ?? null;

    if ($activeTable === null || !in_array($activeTable, $tables, true)) {
        lanter_redirect('dashboard');

        return;
    }

    $columns = $driver->describeTable($activeTable);

    $rows = '';
    foreach ($columns as $column) {
        $keyBadge = $column['key'] !== '' ? '<span class="lanter-key">' . htmlspecialchars($column['key']) . '</span>' : '';
        $nullable = $column['nullable'] ? 'SIM' : 'NÃO';
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
                <thead><tr><th>Coluna</th><th>Tipo</th><th>Permite Nulo</th><th>Padrão</th><th>Chave</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
        HTML;

    lanter_render_layout('Estrutura: ' . $activeTable, $content, $tables, $activeTable);
}
