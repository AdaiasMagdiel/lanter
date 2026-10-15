<?php

function lanter_action_dashboard(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);

    if ($driver->supportsDatabases() && ($connection['database'] ?? '') === '') {
        $selected = trim((string) ($_GET['database'] ?? ''));

        if ($selected !== '') {
            $auth->login([...$connection, 'database' => $selected]);
            lanter_redirect('dashboard');
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
        lanter_render_layout('Dashboard', lanter_render_welcome($tables, $views), $tables, null, $tableCounts, $views);

        return;
    }

    $columns = $driver->describeTable($activeTable);
    $columnNames = array_column($columns, 'name');

    $state = lanter_grid_state($config, $activeTable, $columnNames);
    $rows = $driver->fetchRows(
        $activeTable,
        $state['perPage'],
        ($state['page'] - 1) * $state['perPage'],
        $state['search'] !== '' ? $state['search'] : null,
        $state['sort'],
        $state['dir'],
        $state['filters']
    );

    $isFiltered = $state['search'] !== '' || $state['filters'] !== [];
    $total = $isFiltered
        ? $driver->countRows($activeTable, $state['search'] !== '' ? $state['search'] : null, $state['filters'])
        : ($tableCounts[$activeTable] ?? $driver->countRows($activeTable));

    $foreignKeys = [];
    foreach ($isView ? [] : $driver->listForeignKeys($activeTable) as $fk) {
        $foreignKeys[$fk['column']] = $fk;
    }

    $content = lanter_render_data_table($state, $rows, $total, $columns, $foreignKeys, $isView);

    lanter_render_layout('Data: ' . $activeTable, $content, $tables, $activeTable, $tableCounts, $views);
}

/**
 * Reads paging/sorting/filter state from the query string and session.
 *
 * @param string[] $columnNames
 * @return array{table: string, search: string, page: int, perPage: int, sort: ?string, dir: string, filters: array<string, string>}
 */
function lanter_grid_state(Lanter_Config $config, string $table, array $columnNames): array
{
    $requestedPerPage = (int) ($_GET['per_page'] ?? 0);

    if (in_array($requestedPerPage, Lanter_Config::PAGE_SIZES, true)) {
        $_SESSION['lanter_per_page'] = $requestedPerPage;
    }

    $perPage = $_SESSION['lanter_per_page'] ?? $config->perPage();
    $sort = $_GET['sort'] ?? null;

    $filters = [];
    foreach ((array) ($_GET['f'] ?? []) as $column => $value) {
        if (is_string($value) && trim($value) !== '' && in_array($column, $columnNames, true)) {
            $filters[$column] = trim($value);
        }
    }

    return [
        'table' => $table,
        'search' => trim((string) ($_GET['q'] ?? '')),
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
        'perPage' => $perPage,
        'sort' => $sort !== null && in_array($sort, $columnNames, true) ? $sort : null,
        'dir' => ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
        'filters' => $filters,
    ];
}

/** Builds a dashboard URL for the current grid state, with overrides. */
function lanter_grid_url(array $state, array $overrides = []): string
{
    $query = ['action' => 'dashboard', 'table' => $state['table']];

    if ($state['search'] !== '') {
        $query['q'] = $state['search'];
    }

    if ($state['sort'] !== null) {
        $query['sort'] = $state['sort'];
        $query['dir'] = $state['dir'];
    }

    if ($state['filters'] !== []) {
        $query['f'] = $state['filters'];
    }

    if ($state['page'] > 1) {
        $query['page'] = $state['page'];
    }

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return '?' . http_build_query($query);
}

function lanter_action_change_database(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $auth->login([...$connection, 'database' => '']);
    lanter_redirect('dashboard');
}

function lanter_render_welcome(array $tables, array $views): string
{
    $count = count($tables) + count($views);
    $readOnly = lanter_is_read_only();

    $hint = $count === 0
        ? ($readOnly ? 'This database has no tables yet.' : 'This database is empty. Create a table from the sidebar or run SQL.')
        : 'Select a table from the sidebar, or press <kbd>/</kbd> to filter the list.';

    return '<div class="lanter-empty">' . $hint . '</div>';
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

/**
 * @param array<int, array{name: string, type: string, nullable: bool, default: ?string, key: string}> $columns
 * @param array<string, array{name: string, column: string, refTable: string, refColumn: string}> $foreignKeys
 */
function lanter_render_data_table(array $state, array $rows, int $total, array $columns, array $foreignKeys, bool $isView): string
{
    $table = $state['table'];
    $primaryKey = $isView ? null : lanter_primary_key($columns);
    $canWrite = !lanter_is_read_only() && $primaryKey !== null;
    $columnNames = array_column($columns, 'name');

    $toolbar = lanter_render_grid_toolbar($state, $columnNames, $canWrite);

    if ($rows === []) {
        $message = $state['search'] !== '' || $state['filters'] !== []
            ? 'No rows match the current search or filters.'
            : 'The table "' . htmlspecialchars($table) . '" is empty.';

        return $toolbar . '<div class="lanter-empty">' . $message . '</div>';
    }

    $displayColumns = array_keys($rows[0]);
    $keyIcon = lanter_icon('key', 'lanter-icon lanter-header-key');
    $linkIcon = lanter_icon('link', 'lanter-icon lanter-header-key');

    $headCells = '';
    foreach ($displayColumns as $c) {
        $isActive = $c === $state['sort'];
        $nextDirection = $isActive && $state['dir'] === 'asc' ? 'desc' : 'asc';
        $href = lanter_grid_url($state, ['sort' => $c, 'dir' => $nextDirection, 'page' => null]);
        $arrow = $isActive ? ($state['dir'] === 'asc' ? '↑' : '↓') : '⇅';
        $iconClass = $isActive ? 'lanter-sort-icon active' : 'lanter-sort-icon';
        $badge = $c === $primaryKey ? $keyIcon : (isset($foreignKeys[$c]) ? $linkIcon : '');
        $title = isset($foreignKeys[$c])
            ? ' title="References ' . htmlspecialchars($foreignKeys[$c]['refTable'] . '.' . $foreignKeys[$c]['refColumn']) . '"'
            : '';

        $headCells .= '<th class="lanter-sortable"' . $title . '><a href="' . htmlspecialchars($href) . '">'
            . $badge . htmlspecialchars($c) . '<span class="' . $iconClass . '">' . $arrow . '</span></a></th>';
    }

    $selectAllCell = '';

    if ($canWrite) {
        $selectAllCell = '<th class="lanter-select-col"><input type="checkbox" id="lanter-select-all"></th>';
        $headCells .= '<th>Actions</th>';
    }

    $head = '<tr>' . $selectAllCell . $headCells . '</tr>';

    $body = '';
    foreach ($rows as $row) {
        $cells = '';

        foreach ($row as $column => $value) {
            $cells .= lanter_render_cell($value, $foreignKeys[$column] ?? null);
        }

        $selectCell = '';

        if ($canWrite) {
            $pkValue = (string) $row[$primaryKey];
            $selectCell = '<td class="lanter-select-col"><input type="checkbox" class="lanter-row-select" value="' . htmlspecialchars($pkValue) . '"></td>';
            $cells .= lanter_render_row_actions($table, $pkValue);
        }

        $body .= '<tr>' . $selectCell . $cells . '</tr>';
    }

    $lastPage = max(1, (int) ceil($total / $state['perPage']));
    $pagination = lanter_render_pagination($state, $lastPage, $total);
    $bulkDeleteScript = $canWrite ? lanter_render_bulk_delete_script($table) : '';

    return <<<HTML
        {$toolbar}
        <div class="lanter-panel lanter-grid-panel">
            <table class="lanter-table">
                <thead>{$head}</thead>
                <tbody>{$body}</tbody>
            </table>
        </div>
        {$pagination}
        {$bulkDeleteScript}
        HTML;
}

/** @param array{name: string, column: string, refTable: string, refColumn: string}|null $foreignKey */
function lanter_render_cell(mixed $value, ?array $foreignKey): string
{
    if ($value === null) {
        return '<td><span class="lanter-null">NULL</span></td>';
    }

    $text = (string) $value;

    if ($foreignKey !== null && $text !== '') {
        $href = '?' . http_build_query([
            'action' => 'dashboard',
            'table' => $foreignKey['refTable'],
            'f' => [$foreignKey['refColumn'] => '=' . $text],
        ]);

        return '<td><a class="lanter-fk-link" href="' . htmlspecialchars($href) . '" title="Open in '
            . htmlspecialchars($foreignKey['refTable']) . '">' . htmlspecialchars($text) . '</a></td>';
    }

    $isLong = strlen($text) > 120 || str_contains($text, "\n");

    if ($isLong) {
        $preview = mb_substr(str_replace(["\r", "\n"], ' ', $text), 0, 120) . '…';

        return '<td><button type="button" class="lanter-cell-long" data-full="' . htmlspecialchars($text) . '">'
            . htmlspecialchars($preview) . '</button></td>';
    }

    return '<td>' . htmlspecialchars($text) . '</td>';
}

/** @param string[] $columnNames */
function lanter_render_grid_toolbar(array $state, array $columnNames, bool $canWrite): string
{
    $table = $state['table'];
    $hidden = '<input type="hidden" name="action" value="dashboard">'
        . '<input type="hidden" name="table" value="' . htmlspecialchars($table) . '">';

    if ($state['sort'] !== null) {
        $hidden .= '<input type="hidden" name="sort" value="' . htmlspecialchars($state['sort']) . '">'
            . '<input type="hidden" name="dir" value="' . htmlspecialchars($state['dir']) . '">';
    }

    $bulkActionsBar = $canWrite
        ? '<div class="lanter-bulk-actions" id="lanter-bulk-actions" hidden>'
            . '<button type="button" class="lanter-btn lanter-btn-danger" id="lanter-bulk-delete-btn">' . lanter_icon('trash')
            . '<span>Delete selected (<span id="lanter-bulk-count">0</span>)</span></button>'
            . '</div>'
        : '';

    $perPageOptions = '';
    foreach (Lanter_Config::PAGE_SIZES as $size) {
        $perPageOptions .= '<option value="' . $size . '"' . ($size === $state['perPage'] ? ' selected' : '') . '>' . $size . ' / page</option>';
    }

    $hasFilters = $state['filters'] !== [];
    $filterInputs = '';
    foreach ($columnNames as $column) {
        $value = $state['filters'][$column] ?? '';
        $filterInputs .= '<label class="lanter-filter-field"><span>' . htmlspecialchars($column) . '</span>'
            . '<input type="text" name="f[' . htmlspecialchars($column) . ']" value="' . htmlspecialchars($value) . '" placeholder="contains, = x, > 5, null"></label>';
    }

    $filtersPanel = '<div class="lanter-filters" id="lanter-filters"' . ($hasFilters ? '' : ' hidden') . '>'
        . $filterInputs
        . '<div class="lanter-filters-actions">'
        . '<button class="lanter-btn" type="submit">Apply filters</button>'
        . '<a class="lanter-btn lanter-btn-ghost" href="' . htmlspecialchars(lanter_grid_url($state, ['f' => null, 'page' => null])) . '">Clear</a>'
        . '</div></div>';

    $filterToggleClass = 'lanter-btn lanter-btn-ghost' . ($hasFilters ? ' lanter-btn-active' : '');
    $filterCount = $hasFilters ? ' (' . count($state['filters']) . ')' : '';

    return '<form class="lanter-toolbar" method="get" id="lanter-grid-form">'
        . $hidden
        . '<span class="lanter-search-wrap">' . lanter_icon('search')
        . '<input class="lanter-search" type="text" name="q" id="lanter-grid-search" placeholder="Search in ' . htmlspecialchars($table) . '..." value="' . htmlspecialchars($state['search']) . '"></span>'
        . '<button class="lanter-btn lanter-btn-ghost" type="submit">Search</button>'
        . '<button class="' . $filterToggleClass . '" type="button" id="lanter-filter-toggle">' . lanter_icon('filter') . '<span>Filters' . $filterCount . '</span></button>'
        . '<select class="lanter-select-inline" name="per_page" onchange="this.form.submit()">' . $perPageOptions . '</select>'
        . $bulkActionsBar
        . $filtersPanel
        . '</form>';
}

function lanter_render_bulk_delete_script(string $table): string
{
    $tableEscaped = htmlspecialchars($table, ENT_QUOTES);
    $token = htmlspecialchars(lanter_csrf_token(), ENT_QUOTES);

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

                    addField('_token', '{$token}');
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
    $csrf = lanter_csrf_field();

    $editIcon = lanter_icon('pencil');
    $trashIcon = lanter_icon('trash');

    return <<<HTML
        <td class="lanter-row-actions">
            <a class="lanter-action-link" href="{$editHref}" title="Edit">{$editIcon}</a>
            <form method="post" action="?action=row_delete" onsubmit="return confirm('Delete this row?')">
                {$csrf}
                <input type="hidden" name="table" value="{$tableAttr}">
                <input type="hidden" name="pk" value="{$pkAttr}">
                <button class="lanter-action-link lanter-action-danger" type="submit" title="Delete">{$trashIcon}</button>
            </form>
        </td>
        HTML;
}

function lanter_render_pagination(array $state, int $lastPage, int $total): string
{
    $page = $state['page'];

    $prev = $page > 1
        ? '<a class="lanter-page-link" href="' . htmlspecialchars(lanter_grid_url($state, ['page' => $page - 1])) . '">&larr; Previous</a>'
        : '<span class="lanter-page-link disabled">&larr; Previous</span>';

    $next = $page < $lastPage
        ? '<a class="lanter-page-link" href="' . htmlspecialchars(lanter_grid_url($state, ['page' => $page + 1])) . '">Next &rarr;</a>'
        : '<span class="lanter-page-link disabled">Next &rarr;</span>';

    return <<<HTML
        <div class="lanter-pagination">
            <span class="lanter-pagination-info">Page {$page} of {$lastPage} · {$total} rows</span>
            <div class="lanter-pagination-nav">{$prev}{$next}</div>
        </div>
        HTML;
}
