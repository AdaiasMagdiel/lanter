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

    $search = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 25;
    $rows = $driver->fetchRows($activeTable, $perPage, ($page - 1) * $perPage, $search !== '' ? $search : null);
    $total = $driver->countRows($activeTable, $search !== '' ? $search : null);

    $content = lanter_render_data_table($activeTable, $rows, $total, $page, $perPage, $search);

    lanter_render_layout('Dados: ' . $activeTable, $content, $tables, $activeTable);
}

function lanter_render_data_table(string $table, array $rows, int $total, int $page, int $perPage, string $search): string
{
    $searchBar = '<form class="lanter-toolbar" method="get">'
        . '<input type="hidden" name="action" value="dashboard">'
        . '<input type="hidden" name="table" value="' . htmlspecialchars($table) . '">'
        . '<input class="lanter-search" type="text" name="q" placeholder="Buscar em ' . htmlspecialchars($table) . '..." value="' . htmlspecialchars($search) . '">'
        . '<button class="lanter-btn" type="submit">Buscar</button>'
        . '</form>';

    if ($rows === []) {
        $message = $search !== ''
            ? 'Nenhum registro encontrado para "' . htmlspecialchars($search) . '".'
            : 'A tabela "' . htmlspecialchars($table) . '" está vazia.';

        return $searchBar . '<div class="lanter-empty">' . $message . '</div>';
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

    $lastPage = max(1, (int) ceil($total / $perPage));
    $pagination = lanter_render_pagination($table, $search, $page, $lastPage, $total);

    return <<<HTML
        {$searchBar}
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead>{$head}</thead>
                <tbody>{$body}</tbody>
            </table>
        </div>
        {$pagination}
        HTML;
}

function lanter_render_pagination(string $table, string $search, int $page, int $lastPage, int $total): string
{
    $link = static function (int $targetPage) use ($table, $search): string {
        $query = ['action' => 'dashboard', 'table' => $table, 'page' => $targetPage];

        if ($search !== '') {
            $query['q'] = $search;
        }

        return '?' . http_build_query($query);
    };

    $prev = $page > 1
        ? '<a class="lanter-page-link" href="' . htmlspecialchars($link($page - 1)) . '">&larr; Anterior</a>'
        : '<span class="lanter-page-link disabled">&larr; Anterior</span>';

    $next = $page < $lastPage
        ? '<a class="lanter-page-link" href="' . htmlspecialchars($link($page + 1)) . '">Próxima &rarr;</a>'
        : '<span class="lanter-page-link disabled">Próxima &rarr;</span>';

    return <<<HTML
        <div class="lanter-pagination">
            <span class="lanter-pagination-info">Página {$page} de {$lastPage} · {$total} registros</span>
            <div class="lanter-pagination-nav">{$prev}{$next}</div>
        </div>
        HTML;
}
