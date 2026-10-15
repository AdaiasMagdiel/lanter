<?php

function lanter_action_table_structure(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $views = $driver->listViews();
    $activeTable = $_GET['table'] ?? null;
    $isView = $activeTable !== null && in_array($activeTable, $views, true);

    if ($activeTable === null || (!in_array($activeTable, $tables, true) && !$isView)) {
        lanter_redirect('dashboard');
    }

    $columns = $driver->describeTable($activeTable);
    $indexes = $isView ? [] : $driver->listIndexes($activeTable);
    $foreignKeys = $isView ? [] : $driver->listForeignKeys($activeTable);
    $canWrite = !lanter_is_read_only($connection);

    $content = lanter_render_columns_section($activeTable, $columns, $foreignKeys, $canWrite && !$isView, lanter_driver_kind($connection))
        . ($isView ? '' : lanter_render_indexes_section($activeTable, $indexes, $columns, $canWrite))
        . ($isView ? '' : lanter_render_foreign_keys_section($foreignKeys))
        . ($canWrite ? lanter_render_table_danger_zone($activeTable, $isView) : '');

    lanter_render_layout('Structure: ' . $activeTable, $content, $tables, $activeTable, lanter_table_counts($driver, $tables), $views);
}

/**
 * @param array<int, array{name: string, type: string, nullable: bool, default: ?string, key: string, autoIncrement: bool}> $columns
 * @param array<int, array{name: string, column: string, refTable: string, refColumn: string}> $foreignKeys
 */
function lanter_render_columns_section(string $table, array $columns, array $foreignKeys, bool $canWrite, string $driverKind): string
{
    $keyLabels = ['PRI' => 'PK', 'UNI' => 'UNIQUE', 'MUL' => 'INDEX'];
    $fkByColumn = array_column($foreignKeys, null, 'column');
    $tableAttr = htmlspecialchars($table);
    $csrf = lanter_csrf_field();

    $rows = '';
    foreach ($columns as $column) {
        $keyLabel = $keyLabels[$column['key']] ?? $column['key'];
        $badges = $column['key'] !== ''
            ? '<span class="lanter-key">' . lanter_icon('key') . htmlspecialchars($keyLabel) . '</span>'
            : '';

        if (isset($fkByColumn[$column['name']])) {
            $fk = $fkByColumn[$column['name']];
            $badges .= ' <a class="lanter-key lanter-key-fk" href="?action=table_structure&amp;table=' . urlencode($fk['refTable']) . '" title="Foreign key">'
                . lanter_icon('link') . htmlspecialchars($fk['refTable'] . '.' . $fk['refColumn']) . '</a>';
        }

        if ($column['autoIncrement']) {
            $badges .= ' <span class="lanter-badge">AUTO</span>';
        }

        $nullable = $column['nullable']
            ? '<span class="lanter-badge lanter-badge-yes">YES</span>'
            : '<span class="lanter-badge">NO</span>';
        $default = $column['default'] === null
            ? '<span class="lanter-null">NULL</span>'
            : htmlspecialchars((string) $column['default']);
        $nameAttr = htmlspecialchars($column['name']);

        $actions = '';
        if ($canWrite) {
            $actions = '<td class="lanter-row-actions">'
                . '<form method="post" action="?action=table_alter" class="lanter-inline-form" data-prompt="New name for column ' . $nameAttr . ':" data-prompt-field="to" data-prompt-default="' . $nameAttr . '">'
                . $csrf
                . '<input type="hidden" name="op" value="rename_column"><input type="hidden" name="table" value="' . $tableAttr . '">'
                . '<input type="hidden" name="column" value="' . $nameAttr . '"><input type="hidden" name="to" value="">'
                . '<button class="lanter-action-link" type="submit" title="Rename column">' . lanter_icon('pencil') . '</button></form>'
                . '<form method="post" action="?action=table_alter" class="lanter-inline-form" onsubmit="return confirm(\'Drop column ' . $nameAttr . '? Its data will be lost.\')">'
                . $csrf
                . '<input type="hidden" name="op" value="drop_column"><input type="hidden" name="table" value="' . $tableAttr . '">'
                . '<input type="hidden" name="column" value="' . $nameAttr . '">'
                . '<button class="lanter-action-link lanter-action-danger" type="submit" title="Drop column">' . lanter_icon('trash') . '</button></form>'
                . '</td>';
        }

        $rows .= '<tr>'
            . '<td>' . $nameAttr . '</td>'
            . '<td><code>' . htmlspecialchars($column['type']) . '</code></td>'
            . '<td>' . $nullable . '</td>'
            . '<td>' . $default . '</td>'
            . '<td>' . $badges . '</td>'
            . $actions
            . '</tr>';
    }

    $actionsHead = $canWrite ? '<th>Actions</th>' : '';
    $addColumnForm = $canWrite ? lanter_render_add_column_form($table, $driverKind) : '';

    return <<<HTML
        <h2 class="lanter-section-title">Columns</h2>
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th><th>Keys</th>{$actionsHead}</tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
        {$addColumnForm}
        HTML;
}

function lanter_render_add_column_form(string $table, string $driverKind): string
{
    $tableAttr = htmlspecialchars($table);
    $csrf = lanter_csrf_field();
    $types = lanter_table_builder_types($driverKind);
    $options = implode('', array_map(
        static fn (string $type): string => '<option value="' . htmlspecialchars($type) . '">' . htmlspecialchars($type) . '</option>',
        $types
    ));
    $options .= '<option value="' . LANTER_CUSTOM_TYPE_OPTION . '">Custom&hellip;</option>';

    return <<<HTML
        <details class="lanter-details">
            <summary>+ Add column</summary>
            <form method="post" action="?action=table_alter" class="lanter-inline-grid-form">
                {$csrf}
                <input type="hidden" name="op" value="add_column">
                <input type="hidden" name="table" value="{$tableAttr}">
                <div class="lanter-column-row">
                    <input type="text" name="name" placeholder="column_name" class="lanter-column-name" required>
                    <select name="type" class="lanter-column-type lanter-column-type-select">{$options}</select>
                    <input type="text" name="custom_type" placeholder="e.g. ENUM('a','b')" class="lanter-column-custom-type" style="display:none">
                    <input type="text" name="default" placeholder="default (optional)" class="lanter-column-default">
                    <label class="lanter-column-flag" title="Not null"><input type="checkbox" name="not_null"><span>N</span></label>
                    <button class="lanter-btn" type="submit">Add</button>
                </div>
            </form>
        </details>
        HTML;
}

/**
 * @param array<int, array{name: string, columns: string[], unique: bool, primary: bool}> $indexes
 * @param array<int, array{name: string}> $columns
 */
function lanter_render_indexes_section(string $table, array $indexes, array $columns, bool $canWrite): string
{
    $tableAttr = htmlspecialchars($table);
    $csrf = lanter_csrf_field();
    $rows = '';

    foreach ($indexes as $index) {
        $kind = $index['primary']
            ? '<span class="lanter-key">' . lanter_icon('key') . 'PRIMARY</span>'
            : ($index['unique'] ? '<span class="lanter-badge lanter-badge-yes">UNIQUE</span>' : '<span class="lanter-badge">INDEX</span>');
        $nameAttr = htmlspecialchars($index['name']);

        $actions = '';
        if ($canWrite && !$index['primary']) {
            $actions = '<td class="lanter-row-actions">'
                . '<form method="post" action="?action=table_alter" class="lanter-inline-form" onsubmit="return confirm(\'Drop index ' . $nameAttr . '?\')">'
                . $csrf
                . '<input type="hidden" name="op" value="drop_index"><input type="hidden" name="table" value="' . $tableAttr . '">'
                . '<input type="hidden" name="name" value="' . $nameAttr . '">'
                . '<button class="lanter-action-link lanter-action-danger" type="submit" title="Drop index">' . lanter_icon('trash') . '</button></form>'
                . '</td>';
        } elseif ($canWrite) {
            $actions = '<td></td>';
        }

        $rows .= '<tr>'
            . '<td>' . $nameAttr . '</td>'
            . '<td><code>' . htmlspecialchars(implode(', ', $index['columns'])) . '</code></td>'
            . '<td>' . $kind . '</td>'
            . $actions
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="4"><span class="lanter-hint">No indexes.</span></td></tr>';
    }

    $actionsHead = $canWrite ? '<th>Actions</th>' : '';
    $createForm = '';

    if ($canWrite) {
        $checkboxes = implode('', array_map(
            static fn (array $c): string => '<label class="lanter-check-pill"><input type="checkbox" name="columns[]" value="' . htmlspecialchars($c['name']) . '"><span>' . htmlspecialchars($c['name']) . '</span></label>',
            $columns
        ));

        $createForm = <<<HTML
            <details class="lanter-details">
                <summary>+ Add index</summary>
                <form method="post" action="?action=table_alter" class="lanter-inline-grid-form">
                    {$csrf}
                    <input type="hidden" name="op" value="create_index">
                    <input type="hidden" name="table" value="{$tableAttr}">
                    <div class="lanter-column-row">
                        <input type="text" name="name" placeholder="index_name" class="lanter-column-name" required>
                        <label class="lanter-column-flag" title="Unique"><input type="checkbox" name="unique"><span>UNIQUE</span></label>
                        <button class="lanter-btn" type="submit">Create</button>
                    </div>
                    <div class="lanter-check-pills">{$checkboxes}</div>
                </form>
            </details>
            HTML;
    }

    return <<<HTML
        <h2 class="lanter-section-title">Indexes</h2>
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead><tr><th>Name</th><th>Columns</th><th>Type</th>{$actionsHead}</tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
        {$createForm}
        HTML;
}

/** @param array<int, array{name: string, column: string, refTable: string, refColumn: string}> $foreignKeys */
function lanter_render_foreign_keys_section(array $foreignKeys): string
{
    if ($foreignKeys === []) {
        return '';
    }

    $rows = '';
    foreach ($foreignKeys as $fk) {
        $rows .= '<tr>'
            . '<td>' . htmlspecialchars($fk['name']) . '</td>'
            . '<td><code>' . htmlspecialchars($fk['column']) . '</code></td>'
            . '<td><a class="lanter-fk-link" href="?action=table_structure&amp;table=' . urlencode($fk['refTable']) . '">'
            . htmlspecialchars($fk['refTable']) . '</a> . <code>' . htmlspecialchars($fk['refColumn']) . '</code></td>'
            . '</tr>';
    }

    return <<<HTML
        <h2 class="lanter-section-title">Foreign keys</h2>
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead><tr><th>Name</th><th>Column</th><th>References</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
        HTML;
}

function lanter_render_table_danger_zone(string $table, bool $isView): string
{
    $tableAttr = htmlspecialchars($table);
    $csrf = lanter_csrf_field();
    $kind = $isView ? 'view' : 'table';
    $renameOp = $isView ? '' : <<<HTML
        <form method="post" action="?action=table_alter" class="lanter-inline-form" data-prompt="New name for table {$tableAttr}:" data-prompt-field="to" data-prompt-default="{$tableAttr}">
            {$csrf}
            <input type="hidden" name="op" value="rename_table">
            <input type="hidden" name="table" value="{$tableAttr}">
            <input type="hidden" name="to" value="">
            <button class="lanter-btn lanter-btn-ghost" type="submit">Rename table</button>
        </form>
        <form method="post" action="?action=table_alter" class="lanter-inline-form" onsubmit="return confirm('Delete ALL rows from {$tableAttr}? This cannot be undone.')">
            {$csrf}
            <input type="hidden" name="op" value="truncate">
            <input type="hidden" name="table" value="{$tableAttr}">
            <button class="lanter-btn lanter-btn-ghost" type="submit">Truncate</button>
        </form>
        HTML;

    return <<<HTML
        <h2 class="lanter-section-title lanter-section-danger">Danger zone</h2>
        <div class="lanter-panel lanter-danger-zone">
            {$renameOp}
            <form method="post" action="?action=table_alter" class="lanter-inline-form" onsubmit="return confirm('Drop {$kind} {$tableAttr}? This cannot be undone.')">
                {$csrf}
                <input type="hidden" name="op" value="drop">
                <input type="hidden" name="table" value="{$tableAttr}">
                <button class="lanter-btn lanter-btn-danger" type="submit">Drop {$kind}</button>
            </form>
        </div>
        HTML;
}
