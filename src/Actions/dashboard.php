<?php

function lanter_action_dashboard(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $activeTable = $_GET['table'] ?? null;

    if ($activeTable === null || !in_array($activeTable, $tables, true)) {
        lanter_render_layout('Dashboard', '<div class="lanter-empty">Selecione uma tabela na barra lateral.</div>', $tables);

        return;
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 25;
    $rows = $driver->fetchRows($activeTable, $perPage, ($page - 1) * $perPage);
    $total = $driver->countRows($activeTable);

    $content = lanter_render_data_table($activeTable, $rows, $total, $page, $perPage);

    lanter_render_layout('Dados: ' . $activeTable, $content, $tables, $activeTable);
}

function lanter_render_data_table(string $table, array $rows, int $total, int $page, int $perPage): string
{
    if ($rows === []) {
        return '<div class="lanter-empty">A tabela "' . htmlspecialchars($table) . '" está vazia.</div>';
    }

    $columns = array_keys($rows[0]);

    $head = '<tr>' . implode('', array_map(
        static fn (string $c): string => '<th>' . htmlspecialchars($c) . '</th>',
        $columns
    )) . '</tr>';

    $body = '';
    foreach ($rows as $row) {
        $body .= '<tr>' . implode('', array_map(
            static fn ($value): string => '<td>' . htmlspecialchars((string) $value) . '</td>',
            $row
        )) . '</tr>';
    }

    $lastPage = (int) ceil($total / $perPage);

    return <<<HTML
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead>{$head}</thead>
                <tbody>{$body}</tbody>
            </table>
        </div>
        <p style="color:var(--text-muted);font-size:12px;margin-top:8px">
            Página {$page} de {$lastPage} · {$total} registros
        </p>
        HTML;
}
