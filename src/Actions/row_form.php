<?php

function lanter_action_row_new(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $table = $_GET['table'] ?? '';

    if (!in_array($table, $tables, true)) {
        lanter_redirect('dashboard');

        return;
    }

    $columns = $driver->describeTable($table);
    $error = null;
    $values = $_POST;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = lanter_collect_insert_data($columns, $_POST);

        try {
            $driver->insertRow($table, $data);
            lanter_redirect('dashboard', ['table' => $table]);

            return;
        } catch (Throwable $e) {
            $error = 'Não foi possível inserir: ' . $e->getMessage();
        }
    }

    $content = lanter_render_row_form(
        $table,
        $columns,
        $values,
        '?action=row_new&table=' . urlencode($table),
        'Novo registro',
        $error
    );

    lanter_render_layout('Novo registro: ' . $table, $content, $tables, $table);
}

function lanter_action_row_edit(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $table = $_GET['table'] ?? '';
    $pkValue = $_GET['pk'] ?? null;

    if (!in_array($table, $tables, true) || $pkValue === null) {
        lanter_redirect('dashboard');

        return;
    }

    $columns = $driver->describeTable($table);
    $primaryKey = lanter_primary_key($columns);

    if ($primaryKey === null) {
        lanter_redirect('dashboard', ['table' => $table]);

        return;
    }

    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = lanter_collect_update_data($columns, $_POST);

        try {
            $driver->updateRow($table, $primaryKey, $_POST['__pk_original'] ?? $pkValue, $data);
            lanter_redirect('dashboard', ['table' => $table]);

            return;
        } catch (Throwable $e) {
            $error = 'Não foi possível atualizar: ' . $e->getMessage();
        }
    }

    $row = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $driver->findRow($table, $primaryKey, $pkValue);

    if ($row === null) {
        lanter_redirect('dashboard', ['table' => $table]);

        return;
    }

    $content = lanter_render_row_form(
        $table,
        $columns,
        $row,
        '?action=row_edit&table=' . urlencode($table) . '&pk=' . urlencode((string) $pkValue),
        'Editar registro',
        $error,
        (string) $pkValue
    );

    lanter_render_layout('Editar: ' . $table, $content, $tables, $table);
}

/** @param array<int, array{name: string, type: string, nullable: bool, default: ?string, key: string}> $columns */
function lanter_collect_insert_data(array $columns, array $input): array
{
    $data = [];

    foreach ($columns as $column) {
        $value = lanter_collect_column_value($column, $input);

        if ($column['key'] === 'PRI' && $value === '') {
            continue;
        }

        $data[$column['name']] = $value === '' && $column['nullable'] ? null : $value;
    }

    return $data;
}

/** @param array<int, array{name: string, type: string, nullable: bool, default: ?string, key: string}> $columns */
function lanter_collect_update_data(array $columns, array $input): array
{
    $data = [];

    foreach ($columns as $column) {
        $value = lanter_collect_column_value($column, $input);
        $data[$column['name']] = $value === '' && $column['nullable'] ? null : $value;
    }

    return $data;
}

/** @param array{name: string, type: string, nullable: bool, default: ?string, key: string} $column */
function lanter_collect_column_value(array $column, array $input): string
{
    $kind = lanter_column_input_spec($column['type'])['kind'];

    if ($kind === 'checkbox') {
        return isset($input[$column['name']]) && $input[$column['name']] !== '0' ? '1' : '0';
    }

    $value = (string) ($input[$column['name']] ?? '');

    return lanter_format_value_for_storage($kind, $value);
}

/** @param array<int, array{name: string, type: string, nullable: bool, default: ?string, key: string}> $columns */
function lanter_render_row_form(
    string $table,
    array $columns,
    array $values,
    string $action,
    string $title,
    ?string $error,
    ?string $originalPk = null
): string {
    $fields = '';

    foreach ($columns as $column) {
        $name = $column['name'];
        $hint = $column['type'] . ($column['nullable'] ? ', permite nulo' : '');
        $input = lanter_render_field_input($column, $values[$name] ?? null);

        $fields .= <<<HTML
            <div class="lanter-field">
                <label>{$name} <span class="lanter-hint">({$hint})</span></label>
                {$input}
            </div>
            HTML;
    }

    $hiddenPk = $originalPk !== null
        ? '<input type="hidden" name="__pk_original" value="' . htmlspecialchars($originalPk) . '">'
        : '';

    $errorHtml = $error !== null
        ? '<p style="color:var(--danger)">' . htmlspecialchars($error) . '</p>'
        : '';

    $backLink = '?action=dashboard&table=' . urlencode($table);

    return <<<HTML
        <div class="lanter-panel lanter-form-panel">
            <h2 class="lanter-form-title">{$title}</h2>
            {$errorHtml}
            <form method="post" action="{$action}">
                {$hiddenPk}
                {$fields}
                <div class="lanter-form-actions">
                    <button class="lanter-btn" type="submit">Salvar</button>
                    <a class="lanter-btn lanter-btn-ghost" href="{$backLink}">Cancelar</a>
                </div>
            </form>
        </div>
        HTML;
}

/** @param array{name: string, type: string, nullable: bool, default: ?string, key: string} $column */
function lanter_render_field_input(array $column, mixed $rawValue): string
{
    $name = $column['name'];
    $spec = lanter_column_input_spec($column['type']);
    $kind = $spec['kind'];
    $value = lanter_format_value_for_display($kind, $rawValue);

    if ($kind === 'checkbox') {
        $checked = lanter_is_truthy_value($rawValue) ? ' checked' : '';

        return '<input type="hidden" name="' . $name . '" value="0">'
            . '<input type="checkbox" name="' . $name . '" value="1"' . $checked . '>';
    }

    if ($kind === 'textarea') {
        return '<textarea name="' . $name . '" rows="4">' . htmlspecialchars($value) . '</textarea>';
    }

    if ($kind === 'select') {
        $options = '';

        if ($column['nullable']) {
            $options .= '<option value=""></option>';
        }

        foreach ($spec['options'] as $option) {
            $selected = $option === $value ? ' selected' : '';
            $options .= '<option value="' . htmlspecialchars($option) . '"' . $selected . '>'
                . htmlspecialchars($option) . '</option>';
        }

        return '<select name="' . $name . '">' . $options . '</select>';
    }

    $step = $spec['step'] !== null ? ' step="' . $spec['step'] . '"' : '';

    return '<input type="' . $kind . '" name="' . $name . '" value="' . htmlspecialchars($value) . '"' . $step . '>';
}
