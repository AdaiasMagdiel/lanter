<?php

function lanter_action_sql(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);

    $sql = trim((string) ($_POST['sql'] ?? ''));
    $result = null;
    $error = null;
    $elapsedMs = null;

    if ($sql === '' && $_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['template'] ?? '') === 'create_table') {
        $isSqlite = ($connection['driver'] ?? 'sqlite') === 'sqlite';
        $sql = $isSqlite
            ? "CREATE TABLE table_name (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    name TEXT NOT NULL\n);"
            : "CREATE TABLE table_name (\n    id INT AUTO_INCREMENT PRIMARY KEY,\n    name VARCHAR(255) NOT NULL\n);";
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sql !== '') {
        $start = microtime(true);

        try {
            $result = $driver->runQuery($sql);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $elapsedMs = round((microtime(true) - $start) * 1000, 2);
    }

    $tables = $driver->listTables();
    $views = $driver->listViews();
    $content = lanter_render_sql_console($sql, $result, $error, $elapsedMs);

    lanter_render_layout('SQL Console', $content, $tables, null, lanter_table_counts($driver, $tables), $views);
}

/** @param ?array{columns: string[], rows: array<int, array<string, mixed>>, affected: int} $result */
function lanter_render_sql_console(string $sql, ?array $result, ?string $error, ?float $elapsedMs): string
{
    $sqlEscaped = htmlspecialchars($sql);
    $terminalIcon = lanter_icon('terminal');
    $runIcon = lanter_icon('play');

    $form = <<<HTML
        <form class="lanter-sql-form" method="post" action="?action=sql">
            <div class="lanter-sql-console">
                <div class="lanter-sql-console-head">
                    <span>{$terminalIcon}<span>query.sql</span></span>
                    <span class="lanter-sql-shortcut"><kbd>Ctrl</kbd> + <kbd>Enter</kbd></span>
                </div>
                <textarea class="lanter-sql-editor" name="sql" id="lanter-sql-editor" rows="8" placeholder="SELECT * FROM ..." spellcheck="false">{$sqlEscaped}</textarea>
                <div class="lanter-sql-actions">
                    <button class="lanter-btn" type="submit">{$runIcon}<span>Run</span></button>
                </div>
            </div>
        </form>
        <script>
            (function () {
                var editor = document.getElementById('lanter-sql-editor');
                if (!editor) { return; }
                editor.addEventListener('keydown', function (event) {
                    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                        editor.form.submit();
                    }
                });
            })();
        </script>
        HTML;

    if ($error !== null) {
        return $form . '<div class="lanter-sql-error">' . htmlspecialchars($error) . '</div>';
    }

    if ($result === null) {
        return $form;
    }

    $timing = $elapsedMs !== null ? $elapsedMs . 'ms · ' : '';

    if ($result['columns'] === []) {
        return $form . '<p class="lanter-sql-meta">' . $timing . $result['affected'] . ' row(s) affected</p>';
    }

    $meta = '<p class="lanter-sql-meta">' . $timing . count($result['rows']) . ' row(s) returned</p>';

    $head = '<tr>' . implode('', array_map(
        static fn (string $c): string => '<th>' . htmlspecialchars($c) . '</th>',
        $result['columns']
    )) . '</tr>';

    $body = '';
    foreach ($result['rows'] as $row) {
        $body .= '<tr>' . implode('', array_map(
            static fn ($value): string => '<td>' . htmlspecialchars((string) $value) . '</td>',
            $row
        )) . '</tr>';
    }

    return <<<HTML
        {$form}
        {$meta}
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead>{$head}</thead>
                <tbody>{$body}</tbody>
            </table>
        </div>
        HTML;
}
