<?php

function lanter_action_dashboard(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);

    if ($driver->supportsDatabases() && ($connection['database'] ?? '') === '') {
        $selected = trim((string) ($_GET['database'] ?? ''));

        if ($selected !== '') {
            $auth->login([...$connection, 'database' => $selected]);
            lanter_redirect('dashboard');

            return;
        }

        lanter_render_layout('Databases', lanter_render_database_list($driver->listDatabases()), []);

        return;
    }

    $tables = $driver->listTables();
    $views = $driver->listViews();
    $tableCounts = lanter_table_counts($driver, $tables);
    $activeTable = $_GET['table'] ?? null;
    $isView = $activeTable !== null && in_array($activeTable, $views, true);

    if ($activeTable === null || (!in_array($activeTable, $tables, true) && !$isView)) {
        lanter_render_layout('Dashboard', '<div class="lanter-empty">Select a table from the sidebar.</div>', $tables, null, $tableCounts, $views);

        return;
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 25;
    $columns = $driver->describeTable($activeTable);
    $columnNames = array_column($columns, 'name');
    $sortColumn = $_GET['sort'] ?? null;
    $sortColumn = $sortColumn !== null && in_array($sortColumn, $columnNames, true) ? $sortColumn : null;
    $sortDirection = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

    $rows = $driver->fetchRows($activeTable, $perPage, ($page - 1) * $perPage, $search !== '' ? $search : null, $sortColumn, $sortDirection);
    $total = $search !== '' ? $driver->countRows($activeTable, $search) : ($tableCounts[$activeTable] ?? $driver->countRows($activeTable));
    $primaryKey = lanter_primary_key($columns);

    $content = lanter_render_data_table($activeTable, $rows, $total, $page, $perPage, $search, $primaryKey, $sortColumn, $sortDirection);

    lanter_render_layout('Data: ' . $activeTable, $content, $tables, $activeTable, $tableCounts, $views);
}

function lanter_action_change_database(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $auth->login([...$connection, 'database' => '']);
    lanter_redirect('dashboard');
}

function lanter_render_database_list(array $databases): string
{
    if ($databases === []) {
        return '<div class="lanter-empty">No databases found.</div>';
    }

    $databaseIcon = lanter_icon('database');

    $items = implode('', array_map(
        static fn (string $database): string => '<a class="lanter-table-link" href="?action=dashboard&amp;database='
            . urlencode($database) . '">' . $databaseIcon . '<span>' . htmlspecialchars($database) . '</span></a>',
        $databases
    ));

    return '<div class="lanter-panel lanter-database-list"><nav class="lanter-table-list">' . $items . '</nav></div>';
}

function lanter_render_data_table(
    string $table,
    array $rows,
    int $total,
    int $page,
    int $perPage,
    string $search,
    ?string $primaryKey,
    ?string $sortColumn = null,
    string $sortDirection = 'asc'
): string {
    $sortHiddenFields = $sortColumn !== null
        ? '<input type="hidden" name="sort" value="' . htmlspecialchars($sortColumn) . '">'
            . '<input type="hidden" name="dir" value="' . htmlspecialchars($sortDirection) . '">'
        : '';

    $bulkActionsBar = $primaryKey !== null
        ? '<div class="lanter-bulk-actions" id="lanter-bulk-actions" hidden>'
            . '<button type="button" class="lanter-btn lanter-btn-danger" id="lanter-bulk-delete-btn">' . lanter_icon('trash')
            . '<span>Delete selected (<span id="lanter-bulk-count">0</span>)</span></button>'
            . '</div>'
        : '';

    $searchBar = '<form class="lanter-toolbar" method="get">'
        . '<input type="hidden" name="action" value="dashboard">'
        . '<input type="hidden" name="table" value="' . htmlspecialchars($table) . '">'
        . $sortHiddenFields
        . '<span class="lanter-search-wrap">' . lanter_icon('search') . '<input class="lanter-search" type="text" name="q" placeholder="Search in ' . htmlspecialchars($table) . '..." value="' . htmlspecialchars($search) . '"></span>'
        . '<button class="lanter-btn lanter-btn-ghost" type="submit">Search</button>'
        . $bulkActionsBar
        . '</form>';

    if ($rows === []) {
        $message = $search !== ''
            ? 'No rows found for "' . htmlspecialchars($search) . '".'
            : 'The table "' . htmlspecialchars($table) . '" is empty.';

        return $searchBar . '<div class="lanter-empty">' . $message . '</div>';
    }

    $columns = array_keys($rows[0]);
    $keyIcon = lanter_icon('key', 'lanter-icon lanter-header-key');

    $headCells = implode('', array_map(
        static function (string $c) use ($table, $search, $sortColumn, $sortDirection, $primaryKey, $keyIcon): string {
            $isActive = $c === $sortColumn;
            $nextDirection = $isActive && $sortDirection === 'asc' ? 'desc' : 'asc';
            $query = ['action' => 'dashboard', 'table' => $table, 'sort' => $c, 'dir' => $nextDirection];

            if ($search !== '') {
                $query['q'] = $search;
            }

            $arrow = $isActive
                ? ($sortDirection === 'asc' ? '↑' : '↓')
                : '⇅';
            $iconClass = $isActive ? 'lanter-sort-icon active' : 'lanter-sort-icon';
            $pkIcon = $c === $primaryKey ? $keyIcon : '';

            return '<th class="lanter-sortable"><a href="?' . htmlspecialchars(http_build_query($query)) . '">'
                . $pkIcon . htmlspecialchars($c) . '<span class="' . $iconClass . '">' . $arrow . '</span></a></th>';
        },
        $columns
    ));

    $selectAllCell = '';

    if ($primaryKey !== null) {
        $selectAllCell = '<th class="lanter-select-col"><input type="checkbox" id="lanter-select-all"></th>';
        $headCells .= '<th>Actions</th>';
    }

    $head = '<tr>' . $selectAllCell . $headCells . '</tr>';

    $body = '';
    foreach ($rows as $row) {
        $cells = implode('', array_map(
            static fn ($value): string => '<td>' . htmlspecialchars((string) $value) . '</td>',
            $row
        ));

        $selectCell = '';

        if ($primaryKey !== null) {
            $pkValue = (string) $row[$primaryKey];
            $selectCell = '<td class="lanter-select-col"><input type="checkbox" class="lanter-row-select" value="' . htmlspecialchars($pkValue) . '"></td>';
            $cells .= lanter_render_row_actions($table, $pkValue);
        }

        $body .= '<tr>' . $selectCell . $cells . '</tr>';
    }

    $lastPage = max(1, (int) ceil($total / $perPage));
    $pagination = lanter_render_pagination($table, $search, $page, $lastPage, $total, $sortColumn, $sortDirection);
    $bulkDeleteScript = $primaryKey !== null ? lanter_render_bulk_delete_script($table) : '';

    return <<<HTML
        {$searchBar}
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead>{$head}</thead>
                <tbody>{$body}</tbody>
            </table>
        </div>
        {$pagination}
        {$bulkDeleteScript}
        HTML;
}

function lanter_render_bulk_delete_script(string $table): string
{
    $tableEscaped = htmlspecialchars($table, ENT_QUOTES);

    return <<<HTML
        <script>
            (function () {
                var selectAll = document.getElementById('lanter-select-all');
                var bar = document.getElementById('lanter-bulk-actions');
                var countEl = document.getElementById('lanter-bulk-count');
                var deleteBtn = document.getElementById('lanter-bulk-delete-btn');
                if (!selectAll || !bar) { return; }

                function checkboxes() {
                    return Array.prototype.slice.call(document.querySelectorAll('.lanter-row-select'));
                }

                function sync() {
                    var checked = checkboxes().filter(function (cb) { return cb.checked; });
                    countEl.textContent = String(checked.length);
                    bar.hidden = checked.length === 0;
                }

                selectAll.addEventListener('change', function () {
                    checkboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
                    sync();
                });

                document.addEventListener('change', function (event) {
                    if (event.target.classList.contains('lanter-row-select')) {
                        sync();
                    }
                });

                deleteBtn.addEventListener('click', function () {
                    var checked = checkboxes().filter(function (cb) { return cb.checked; });
                    if (checked.length === 0 || !confirm('Delete ' + checked.length + ' selected row(s)?')) { return; }

                    var form = document.createElement('form');
                    form.method = 'post';
                    form.action = '?action=row_bulk_delete';

                    function addField(name, value) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.appendChild(input);
                    }

                    addField('table', '{$tableEscaped}');
                    checked.forEach(function (cb) { addField('pks[]', cb.value); });

                    document.body.appendChild(form);
                    form.submit();
                });
            })();
        </script>
        HTML;
}

function lanter_render_row_actions(string $table, string $pkValue): string
{
    $editHref = '?action=row_edit&table=' . urlencode($table) . '&pk=' . urlencode($pkValue);
    $tableAttr = htmlspecialchars($table);
    $pkAttr = htmlspecialchars($pkValue);

    $editIcon = lanter_icon('pencil');
    $trashIcon = lanter_icon('trash');

    return <<<HTML
        <td class="lanter-row-actions">
            <a class="lanter-action-link" href="{$editHref}" title="Edit">{$editIcon}</a>
            <form method="post" action="?action=row_delete" onsubmit="return confirm('Delete this row?')">
                <input type="hidden" name="table" value="{$tableAttr}">
                <input type="hidden" name="pk" value="{$pkAttr}">
                <button class="lanter-action-link lanter-action-danger" type="submit" title="Delete">{$trashIcon}</button>
            </form>
        </td>
        HTML;
}

function lanter_render_pagination(
    string $table,
    string $search,
    int $page,
    int $lastPage,
    int $total,
    ?string $sortColumn = null,
    string $sortDirection = 'asc'
): string {
    $link = static function (int $targetPage) use ($table, $search, $sortColumn, $sortDirection): string {
        $query = ['action' => 'dashboard', 'table' => $table, 'page' => $targetPage];

        if ($search !== '') {
            $query['q'] = $search;
        }

        if ($sortColumn !== null) {
            $query['sort'] = $sortColumn;
            $query['dir'] = $sortDirection;
        }

        return '?' . http_build_query($query);
    };

    $prev = $page > 1
        ? '<a class="lanter-page-link" href="' . htmlspecialchars($link($page - 1)) . '">&larr; Previous</a>'
        : '<span class="lanter-page-link disabled">&larr; Previous</span>';

    $next = $page < $lastPage
        ? '<a class="lanter-page-link" href="' . htmlspecialchars($link($page + 1)) . '">Next &rarr;</a>'
        : '<span class="lanter-page-link disabled">Next &rarr;</span>';

    return <<<HTML
        <div class="lanter-pagination">
            <span class="lanter-pagination-info">Page {$page} of {$lastPage} · {$total} rows</span>
            <div class="lanter-pagination-nav">{$prev}{$next}</div>
        </div>
        HTML;
}
