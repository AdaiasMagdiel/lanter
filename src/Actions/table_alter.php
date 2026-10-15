<?php

/** Handles every schema change posted from the Structure tab; each op redirects back with a notice or error. */
function lanter_action_table_alter(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    lanter_assert_writable($connection);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        lanter_redirect('dashboard');
    }

    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $views = $driver->listViews();
    $table = (string) ($_POST['table'] ?? '');
    $op = (string) ($_POST['op'] ?? '');
    $isView = in_array($table, $views, true);

    if (!in_array($table, $tables, true) && !$isView) {
        lanter_redirect('dashboard');
    }

    $redirectTable = $table;

    try {
        switch ($op) {
            case 'drop':
                $isView ? $driver->dropView($table) : $driver->dropTable($table);
                unset($_SESSION['lanter_last_table']);
                lanter_redirect('dashboard', ['notice' => ($isView ? 'View' : 'Table') . ' ' . $table . ' dropped.']);

            case 'truncate':
                $driver->truncateTable($table);
                $notice = 'All rows removed from ' . $table . '.';
                break;

            case 'rename_table':
                $to = trim((string) ($_POST['to'] ?? ''));
                lanter_require_identifier($to, 'Table name');
                $driver->renameTable($table, $to);
                $redirectTable = $to;
                $notice = 'Table renamed to ' . $to . '.';
                break;

            case 'add_column':
                $column = lanter_parse_single_column($_POST, lanter_table_builder_types(lanter_driver_kind($connection)));
                $driver->addColumn($table, $column);
                $notice = 'Column ' . $column['name'] . ' added.';
                break;

            case 'drop_column':
                $column = (string) ($_POST['column'] ?? '');
                lanter_require_existing_column($driver, $table, $column);
                $driver->dropColumn($table, $column);
                $notice = 'Column ' . $column . ' dropped.';
                break;

            case 'rename_column':
                $column = (string) ($_POST['column'] ?? '');
                $to = trim((string) ($_POST['to'] ?? ''));
                lanter_require_existing_column($driver, $table, $column);
                lanter_require_identifier($to, 'Column name');
                $driver->renameColumn($table, $column, $to);
                $notice = 'Column renamed to ' . $to . '.';
                break;

            case 'create_index':
                $name = trim((string) ($_POST['name'] ?? ''));
                $columns = array_values(array_filter((array) ($_POST['columns'] ?? []), 'is_string'));
                lanter_require_identifier($name, 'Index name');

                if ($columns === []) {
                    throw new InvalidArgumentException('Pick at least one column for the index.');
                }

                foreach ($columns as $c) {
                    lanter_require_existing_column($driver, $table, $c);
                }

                $driver->createIndex($table, $name, $columns, isset($_POST['unique']));
                $notice = 'Index ' . $name . ' created.';
                break;

            case 'drop_index':
                $name = (string) ($_POST['name'] ?? '');
                $known = array_column($driver->listIndexes($table), 'name');

                if (!in_array($name, $known, true)) {
                    throw new InvalidArgumentException('Unknown index.');
                }

                $driver->dropIndex($table, $name);
                $notice = 'Index ' . $name . ' dropped.';
                break;

            default:
                throw new InvalidArgumentException('Unknown operation.');
        }
    } catch (Lanter_Halt $halt) {
        throw $halt;
    } catch (Throwable $e) {
        lanter_redirect('table_structure', ['table' => $table, 'error' => $e->getMessage()]);
    }

    lanter_redirect('table_structure', ['table' => $redirectTable, 'notice' => $notice]);
}

function lanter_require_identifier(string $name, string $label): void
{
    if (!lanter_is_valid_identifier($name)) {
        throw new InvalidArgumentException($label . ' must start with a letter or underscore and contain only letters, numbers and underscores.');
    }
}

function lanter_require_existing_column(Lanter_DriverInterface $driver, string $table, string $column): void
{
    if (!in_array($column, array_column($driver->describeTable($table), 'name'), true)) {
        throw new InvalidArgumentException('Unknown column "' . $column . '".');
    }
}

/**
 * @param array<int, string> $allowedTypes
 * @return array{name: string, type: string, nullable: bool, default: ?string}
 */
function lanter_parse_single_column(array $input, array $allowedTypes): array
{
    $name = trim((string) ($input['name'] ?? ''));
    lanter_require_identifier($name, 'Column name');

    $type = (string) ($input['type'] ?? '');

    if ($type === LANTER_CUSTOM_TYPE_OPTION) {
        $type = trim((string) ($input['custom_type'] ?? ''));

        if (!lanter_is_safe_custom_type($type)) {
            throw new InvalidArgumentException('Invalid custom type. Use only letters, numbers, spaces, quotes, commas, dots, parentheses and hyphens.');
        }
    } elseif (!in_array($type, $allowedTypes, true)) {
        throw new InvalidArgumentException('Invalid column type.');
    }

    $default = trim((string) ($input['default'] ?? ''));

    return [
        'name' => $name,
        'type' => $type,
        'nullable' => !isset($input['not_null']),
        'default' => $default === '' ? null : $default,
    ];
}
