<?php

function lanter_action_view_create(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $driver = lanter_make_driver($connection);
    $tables = $driver->listTables();
    $views = $driver->listViews();
    $error = null;
    $viewName = trim((string) ($_POST['view_name'] ?? ''));
    $query = (string) ($_POST['query'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if ($viewName === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $viewName) !== 1) {
                throw new InvalidArgumentException('View name must start with a letter or underscore and contain only letters, numbers and underscores.');
            }

            if (trim($query) === '') {
                throw new InvalidArgumentException('The view query cannot be empty.');
            }

            $driver->createView($viewName, $query);
            lanter_redirect('dashboard', ['table' => $viewName]);

            return;
        } catch (Throwable $e) {
            $error = 'Could not create view: ' . $e->getMessage();
        }
    }

    $content = lanter_render_view_create_form($viewName, $query, $error);

    lanter_render_layout('New view', $content, $tables, null, lanter_table_counts($driver, $tables), $views);
}

function lanter_render_view_create_form(string $viewName, string $query, ?string $error): string
{
    $nameEscaped = htmlspecialchars($viewName);
    $queryEscaped = htmlspecialchars($query);
    $errorHtml = $error !== null ? '<p class="lanter-form-error">' . htmlspecialchars($error) . '</p>' : '';

    return <<<HTML
        <div class="lanter-panel lanter-form-panel lanter-table-create-panel">
            <h2 class="lanter-form-title">New view</h2>
            {$errorHtml}
            <form method="post" action="?action=view_create">
                <div class="lanter-field">
                    <label>View name</label>
                    <input type="text" name="view_name" value="{$nameEscaped}" placeholder="active_users" required>
                </div>
                <div class="lanter-field">
                    <label>SELECT query <span class="lanter-hint">(single statement, no trailing semicolon needed)</span></label>
                    <textarea name="query" rows="6" placeholder="SELECT * FROM users WHERE active = 1" spellcheck="false">{$queryEscaped}</textarea>
                </div>
                <div class="lanter-form-actions">
                    <button class="lanter-btn" type="submit">Create view</button>
                    <a class="lanter-btn lanter-btn-ghost" href="?action=dashboard">Cancel</a>
                </div>
            </form>
        </div>
        HTML;
}
