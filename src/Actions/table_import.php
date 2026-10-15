<?php

function lanter_action_table_import(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    lanter_assert_writable($connection);

    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $views = $driver->listViews();
    $selectedTable = (string) ($_POST['table'] ?? $_GET['table'] ?? '');
    $error = null;
    $notice = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $file = $_FILES['file'] ?? null;

            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Choose a file to upload.');
            }

            $contents = (string) file_get_contents($file['tmp_name']);
            $kind = (string) ($_POST['kind'] ?? 'csv');

            if ($kind === 'sql') {
                $count = lanter_import_sql($driver, $contents);
                $notice = $count . ' statement(s) executed.';
            } else {
                if (!in_array($selectedTable, $tables, true)) {
                    throw new InvalidArgumentException('Pick the destination table.');
                }

                $count = lanter_import_csv($driver, $selectedTable, $contents, ($_POST['truncate'] ?? '') === '1');
                $notice = $count . ' row(s) imported into ' . $selectedTable . '.';
            }
        } catch (Throwable $e) {
            $error = 'Import failed: ' . $e->getMessage();
        }

        // The sidebar counts and table list may have changed.
        $tables = $driver->listTables();
    }

    $content = lanter_render_import_form($tables, $selectedTable, $error, $notice);

    lanter_render_layout('Import', $content, $tables, null, lanter_table_counts($driver, $tables), $views);
}

/** Runs every statement in one transaction so a bad script leaves nothing half-applied (where the engine allows it). */
function lanter_import_sql(Lanter_DriverInterface $driver, string $sql): int
{
    $statements = lanter_split_sql($sql);

    if ($statements === []) {
        throw new InvalidArgumentException('The file has no SQL statements.');
    }

    $driver->beginTransaction();

    try {
        foreach ($statements as $statement) {
            $driver->runQuery($statement);
        }

        $driver->commit();
    } catch (Throwable $e) {
        $driver->rollBack();

        throw $e;
    }

    return count($statements);
}

function lanter_import_csv(Lanter_DriverInterface $driver, string $table, string $csv, bool $truncate): int
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);

    $header = fgetcsv($handle, 0, ',', '"', '\\');

    if ($header === false || $header === [null]) {
        throw new InvalidArgumentException('The CSV file is empty.');
    }

    $header = array_map(static fn ($h): string => trim((string) $h, " \t\xEF\xBB\xBF"), $header);
    $known = array_column($driver->describeTable($table), 'name');
    $unknown = array_diff($header, $known);

    if ($unknown !== []) {
        throw new InvalidArgumentException('Unknown column(s) in header: ' . implode(', ', $unknown) . '.');
    }

    $driver->beginTransaction();
    $count = 0;

    try {
        if ($truncate) {
            $driver->truncateTable($table);
        }

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }

            if (count($row) !== count($header)) {
                throw new InvalidArgumentException('Row ' . ($count + 2) . ' has ' . count($row) . ' value(s), expected ' . count($header) . '.');
            }

            $data = array_combine($header, array_map(static fn ($v): ?string => $v === '' ? null : $v, $row));
            $driver->insertRow($table, $data);
            $count++;
        }

        $driver->commit();
    } catch (Throwable $e) {
        $driver->rollBack();

        throw $e;
    } finally {
        fclose($handle);
    }

    return $count;
}

/** @param string[] $tables */
function lanter_render_import_form(array $tables, string $selectedTable, ?string $error, ?string $notice): string
{
    $csrf = lanter_csrf_field();
    $errorHtml = $error !== null ? '<p class="lanter-form-error">' . htmlspecialchars($error) . '</p>' : '';
    $noticeHtml = $notice !== null ? '<p class="lanter-form-notice">' . htmlspecialchars($notice) . '</p>' : '';
    $maxUpload = htmlspecialchars((string) ini_get('upload_max_filesize'));

    $options = '<option value="">Pick a table…</option>';
    foreach ($tables as $table) {
        $options .= '<option value="' . htmlspecialchars($table) . '"' . ($table === $selectedTable ? ' selected' : '') . '>' . htmlspecialchars($table) . '</option>';
    }

    return <<<HTML
        <div class="lanter-panel lanter-form-panel lanter-table-create-panel">
            <h2 class="lanter-form-title">Import</h2>
            {$errorHtml}
            {$noticeHtml}
            <form method="post" action="?action=table_import" enctype="multipart/form-data" id="lanter-import-form">
                {$csrf}
                <div class="lanter-field">
                    <label>What are you importing?</label>
                    <select name="kind" id="lanter-import-kind">
                        <option value="csv">CSV rows into an existing table</option>
                        <option value="sql">SQL script (runs every statement)</option>
                    </select>
                </div>
                <div class="lanter-field" data-import-kind="csv">
                    <label>Destination table</label>
                    <select name="table">{$options}</select>
                </div>
                <div class="lanter-field" data-import-kind="csv">
                    <label class="lanter-checkbox-row"><input type="checkbox" name="truncate" value="1"><span>Empty the table before importing</span></label>
                    <p class="lanter-hint">The first CSV line must be a header with column names. Empty cells become NULL.</p>
                </div>
                <div class="lanter-field">
                    <label>File <span class="lanter-hint">(max {$maxUpload})</span></label>
                    <input type="file" name="file" accept=".csv,.sql,.txt,text/csv,application/sql,text/plain" required>
                </div>
                <div class="lanter-form-actions">
                    <button class="lanter-btn" type="submit">Import</button>
                    <a class="lanter-btn lanter-btn-ghost" href="?action=dashboard">Cancel</a>
                </div>
            </form>
        </div>
        <script>
            (function () {
                var kind = document.getElementById('lanter-import-kind');
                var fields = document.querySelectorAll('[data-import-kind]');
                function sync() {
                    fields.forEach(function (field) {
                        field.style.display = field.getAttribute('data-import-kind') === kind.value ? '' : 'none';
                    });
                }
                kind.addEventListener('change', sync);
                sync();
            })();
        </script>
        HTML;
}
