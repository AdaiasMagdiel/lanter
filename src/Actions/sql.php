<?php

function lanter_action_sql(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();

    $sql = trim((string) ($_POST['sql'] ?? ''));
    $result = null;
    $error = null;
    $elapsedMs = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sql !== '') {
        $start = microtime(true);

        try {
            $result = $driver->runQuery($sql);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $elapsedMs = round((microtime(true) - $start) * 1000, 2);
    }

    $content = lanter_render_sql_console($sql, $result, $error, $elapsedMs);

    lanter_render_layout('SQL Console', $content, $tables);
}

/** @param ?array{columns: string[], rows: array<int, array<string, mixed>>, affected: int} $result */
function lanter_render_sql_console(string $sql, ?array $result, ?string $error, ?float $elapsedMs): string
{
    $sqlEscaped = htmlspecialchars($sql);

    $form = <<<HTML
        <form class="lanter-sql-form" method="post" action="?action=sql">
            <textarea class="lanter-sql-editor" name="sql" rows="8" placeholder="SELECT * FROM ...">{$sqlEscaped}</textarea>
            <div class="lanter-sql-actions">
                <button class="lanter-btn" type="submit">Executar</button>
            </div>
        </form>
        HTML;

    if ($error !== null) {
        return $form . '<div class="lanter-sql-error">' . htmlspecialchars($error) . '</div>';
    }

    if ($result === null) {
        return $form;
    }

    $timing = $elapsedMs !== null ? $elapsedMs . 'ms · ' : '';

    if ($result['columns'] === []) {
        return $form . '<p class="lanter-sql-meta">' . $timing . $result['affected'] . ' linha(s) afetada(s)</p>';
    }

    $meta = '<p class="lanter-sql-meta">' . $timing . count($result['rows']) . ' linha(s) retornada(s)</p>';

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
