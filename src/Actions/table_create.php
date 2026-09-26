<?php

const LANTER_CUSTOM_TYPE_OPTION = '__custom__';

/** @return array<int, string> */
function lanter_table_builder_types(string $driver): array
{
    if ($driver === 'mysql' || $driver === 'mariadb') {
        return [
            'INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'TINYINT(1)',
            'VARCHAR(255)', 'CHAR(36)', 'TEXT', 'LONGTEXT', 'JSON',
            'DECIMAL(10,2)', 'FLOAT', 'DOUBLE',
            'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR',
            'BOOLEAN', 'BLOB',
        ];
    }

    return [
        'INTEGER', 'BIGINT', 'SMALLINT',
        'TEXT', 'VARCHAR(255)', 'CHAR(36)', 'JSON',
        'REAL', 'DOUBLE', 'NUMERIC', 'DECIMAL(10,2)',
        'BOOLEAN', 'DATE', 'DATETIME', 'TIMESTAMP', 'BLOB',
    ];
}

/** Conservative allowlist for a hand-typed type: blocks statement separators and comment syntax while allowing things like ENUM('a','b') or DECIMAL(12,4). */
function lanter_is_safe_custom_type(string $type): bool
{
    if ($type === '' || strlen($type) > 64) {
        return false;
    }

    if (preg_match('/^[A-Za-z0-9_ (),.\'"-]+$/', $type) !== 1) {
        return false;
    }

    return !str_contains($type, '--') && !str_contains($type, '/*') && !str_contains($type, ';');
}

function lanter_action_table_create(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $views = $driver->listViews();
    $driverType = $connection['driver'] ?? 'sqlite';
    $types = lanter_table_builder_types($driverType);
    $error = null;
    $tableName = trim((string) ($_POST['table_name'] ?? ''));
    $submittedColumns = $_POST['columns'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $columns = lanter_parse_table_builder_columns($submittedColumns ?? [], $types);

            if ($tableName === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName) !== 1) {
                throw new InvalidArgumentException('Table name must start with a letter or underscore and contain only letters, numbers and underscores.');
            }

            if ($columns === []) {
                throw new InvalidArgumentException('Add at least one column.');
            }

            $driver->createTable($tableName, $columns);
            lanter_redirect('dashboard', ['table' => $tableName]);

            return;
        } catch (Throwable $e) {
            $error = 'Could not create table: ' . $e->getMessage();
        }
    }

    $content = lanter_render_table_create_form($tableName, $submittedColumns, $types, $driverType, $error);

    lanter_render_layout('New table', $content, $tables, null, lanter_table_counts($driver, $tables), $views);
}

/**
 * @param array<int, string> $allowedTypes
 * @return array<int, array{name: string, type: string, nullable: bool, primaryKey: bool, autoIncrement: bool}>
 */
function lanter_parse_table_builder_columns(mixed $rawColumns, array $allowedTypes): array
{
    if (!is_array($rawColumns)) {
        return [];
    }

    $columns = [];

    foreach ($rawColumns as $row) {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            continue;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Column name "' . $name . '" must start with a letter or underscore and contain only letters, numbers and underscores.');
        }

        $type = (string) ($row['type'] ?? '');

        if ($type === LANTER_CUSTOM_TYPE_OPTION) {
            $type = trim((string) ($row['custom_type'] ?? ''));

            if (!lanter_is_safe_custom_type($type)) {
                throw new InvalidArgumentException('Invalid custom type for "' . $name . '". Use only letters, numbers, spaces, quotes, commas, dots, parentheses and hyphens.');
            }
        } elseif (!in_array($type, $allowedTypes, true)) {
            throw new InvalidArgumentException('Invalid column type for "' . $name . '".');
        }

        $columns[] = [
            'name' => $name,
            'type' => $type,
            'nullable' => !isset($row['not_null']),
            'primaryKey' => isset($row['primary_key']),
            'autoIncrement' => isset($row['auto_increment']),
        ];
    }

    return $columns;
}

/** @param array<int, string> $types */
function lanter_render_table_create_form(
    string $tableName,
    mixed $submittedColumns,
    array $types,
    string $driverType,
    ?string $error
): string {
    $isSqlite = $driverType === 'sqlite';
    $rows = is_array($submittedColumns) && $submittedColumns !== []
        ? $submittedColumns
        : [['name' => 'id', 'type' => $types[0], 'primary_key' => '1', 'auto_increment' => '1', 'not_null' => '1']];

    $rowsHtml = '';
    foreach (array_values($rows) as $index => $row) {
        $rowsHtml .= lanter_render_table_builder_row($index, $row, $types);
    }

    $templateRow = lanter_render_table_builder_row('__INDEX__', [], $types);
    $errorHtml = $error !== null ? '<p class="lanter-form-error">' . htmlspecialchars($error) . '</p>' : '';
    $autoIncrementHint = $isSqlite
        ? 'Auto-increment applies to a single INTEGER primary key column.'
        : 'Auto-increment applies to the primary key column.';

    return <<<HTML
        <div class="lanter-panel lanter-form-panel lanter-table-create-panel">
            <h2 class="lanter-form-title">New table</h2>
            {$errorHtml}
            <form method="post" action="?action=table_create" id="lanter-table-create-form">
                <div class="lanter-field">
                    <label>Table name</label>
                    <input type="text" name="table_name" value="{$tableName}" placeholder="my_table" required>
                </div>

                <div class="lanter-column-builder-head">
                    <span>Columns</span>
                    <span class="lanter-hint">{$autoIncrementHint}</span>
                </div>

                <div id="lanter-columns-container">
                    {$rowsHtml}
                </div>

                <button type="button" class="lanter-btn lanter-btn-ghost lanter-add-column-btn">+ Add column</button>

                <template id="lanter-column-row-template">{$templateRow}</template>

                <div class="lanter-form-actions">
                    <button class="lanter-btn" type="submit">Create table</button>
                    <a class="lanter-btn lanter-btn-ghost" href="?action=dashboard">Cancel</a>
                </div>
            </form>
        </div>
        HTML;
}

/** @param array<int, string> $types */
function lanter_render_table_builder_row(int|string $index, array $row, array $types): string
{
    $name = htmlspecialchars((string) ($row['name'] ?? ''));
    $selectedType = (string) ($row['type'] ?? $types[0]);
    $isCustom = $selectedType === LANTER_CUSTOM_TYPE_OPTION;
    $customType = htmlspecialchars((string) ($row['custom_type'] ?? ''));

    $options = implode('', array_map(
        static fn (string $type): string => '<option value="' . htmlspecialchars($type) . '"'
            . ($type === $selectedType ? ' selected' : '') . '>' . htmlspecialchars($type) . '</option>',
        $types
    ));
    $options .= '<option value="' . LANTER_CUSTOM_TYPE_OPTION . '"' . ($isCustom ? ' selected' : '') . '>Custom&hellip;</option>';

    $notNull = isset($row['not_null']) ? ' checked' : '';
    $primaryKey = isset($row['primary_key']) ? ' checked' : '';
    $autoIncrement = isset($row['auto_increment']) ? ' checked' : '';
    $trashIcon = lanter_icon('trash');
    $customInputStyle = $isCustom ? '' : ' style="display:none"';

    return <<<HTML
        <div class="lanter-column-row">
            <input type="text" name="columns[{$index}][name]" value="{$name}" placeholder="column_name" class="lanter-column-name">
            <select name="columns[{$index}][type]" class="lanter-column-type lanter-column-type-select">{$options}</select>
            <input type="text" name="columns[{$index}][custom_type]" value="{$customType}" placeholder="e.g. ENUM('a','b')" class="lanter-column-custom-type"{$customInputStyle}>
            <label class="lanter-column-flag" title="Not null"><input type="checkbox" name="columns[{$index}][not_null]"{$notNull}><span>N</span></label>
            <label class="lanter-column-flag" title="Primary key"><input type="checkbox" name="columns[{$index}][primary_key]"{$primaryKey}><span>PK</span></label>
            <label class="lanter-column-flag" title="Auto increment"><input type="checkbox" name="columns[{$index}][auto_increment]"{$autoIncrement}><span>AI</span></label>
            <button type="button" class="lanter-action-link lanter-action-danger lanter-remove-column-btn" title="Remove column">{$trashIcon}</button>
        </div>
        HTML;
}
