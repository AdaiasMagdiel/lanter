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
    $primaryKey = lanter_primary_key($driver->describeTable($activeTable));

    $content = lanter_render_data_table($activeTable, $rows, $total, $page, $perPage, $search, $primaryKey);

    lanter_render_layout('Dados: ' . $activeTable, $content, $tables, $activeTable);
}

function lanter_render_data_table(
    string $table,
    array $rows,
    int $total,
    int $page,
    int $perPage,
    string $search,
    ?string $primaryKey
): string {
    $searchBar = '<form class="lanter-toolbar" method="get">'
        . '<input type="hidden" name="action" value="dashboard">'
        . '<input type="hidden" name="table" value="' . htmlspecialchars($table) . '">'
        . '<input class="lanter-search" type="text" name="q" placeholder="Buscar em ' . htmlspecialchars($table) . '..." value="' . htmlspecialchars($search) . '">'
        . '<button class="lanter-btn" type="submit">Buscar</button>'
        . '<a class="lanter-btn lanter-new-row" href="?action=row_new&amp;table=' . urlencode($table) . '">+ Novo Registro</a>'
        . '</form>';

    if ($rows === []) {
        $message = $search !== ''
            ? 'Nenhum registro encontrado para "' . htmlspecialchars($search) . '".'
            : 'A tabela "' . htmlspecialchars($table) . '" está vazia.';

        return $searchBar . '<div class="lanter-empty">' . $message . '</div>';
    }

    $columns = array_keys($rows[0]);

    $headCells = implode('', array_map(
        static fn (string $c): string => '<th>' . htmlspecialchars($c) . '</th>',
        $columns
    ));

    if ($primaryKey !== null) {
        $headCells .= '<th>Ações</th>';
    }

    $head = '<tr>' . $headCells . '</tr>';

    $body = '';
    foreach ($rows as $row) {
        $cells = implode('', array_map(
            static fn ($value): string => '<td>' . htmlspecialchars((string) $value) . '</td>',
            $row
        ));

        if ($primaryKey !== null) {
            $cells .= lanter_render_row_actions($table, (string) $row[$primaryKey]);
        }

        $body .= '<tr>' . $cells . '</tr>';
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

function lanter_render_row_actions(string $table, string $pkValue): string
{
    $editHref = '?action=row_edit&table=' . urlencode($table) . '&pk=' . urlencode($pkValue);
    $tableAttr = htmlspecialchars($table);
    $pkAttr = htmlspecialchars($pkValue);

    return <<<HTML
        <td class="lanter-row-actions">
            <a class="lanter-action-link" href="{$editHref}">Editar</a>
            <form method="post" action="?action=row_delete" onsubmit="return confirm('Excluir este registro?')">
                <input type="hidden" name="table" value="{$tableAttr}">
                <input type="hidden" name="pk" value="{$pkAttr}">
                <button class="lanter-action-link lanter-action-danger" type="submit">Excluir</button>
            </form>
        </td>
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
