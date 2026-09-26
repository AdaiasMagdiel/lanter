<?php

function lanter_action_login(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $driver = $_POST['driver'] ?? 'sqlite';

        $candidate = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'path' => $_POST['path'] ?? '']
            : [
                'driver' => $driver,
                'host' => $_POST['host'] ?? '127.0.0.1',
                'port' => (int) ($_POST['port'] ?? 3306),
                'database' => $_POST['database'] ?? '',
                'user' => $_POST['user'] ?? '',
                'password' => $_POST['password'] ?? '',
            ];

        try {
            lanter_make_driver($candidate);
            $auth->login($candidate);
            lanter_redirect('dashboard');

            return;
        } catch (Throwable $e) {
            $error = 'Could not connect: ' . $e->getMessage();
        }
    }

    $errorHtml = $error !== null ? '<p class="lanter-form-error">' . htmlspecialchars($error) . '</p>' : '';
    $plugIcon = lanter_icon('check');

    lanter_render_bare('Sign in', <<<HTML
        <div class="lanter-login-page">
            <form class="lanter-login" method="post" action="?action=login">
                <div class="lanter-login-brand">
                    <span class="lanter-brand-icon">&#128161;</span>
                    <h1>Lanter</h1>
                </div>
                {$errorHtml}
                <div class="lanter-field">
                    <label>Driver</label>
                    <select name="driver" id="lanter-login-driver">
                        <option value="sqlite">SQLite</option>
                        <option value="mysql">MySQL / MariaDB</option>
                    </select>
                </div>
                <div class="lanter-field" data-driver-field="sqlite">
                    <label>File path (SQLite)</label>
                    <input type="text" name="path" placeholder="/path/to/database.sqlite">
                </div>
                <div class="lanter-field" data-driver-field="mysql">
                    <label>Host (MySQL)</label>
                    <input type="text" name="host" placeholder="127.0.0.1">
                </div>
                <div class="lanter-field" data-driver-field="mysql">
                    <label>Port</label>
                    <input type="text" name="port" placeholder="3306">
                </div>
                <div class="lanter-field" data-driver-field="mysql">
                    <label>Database</label>
                    <input type="text" name="database">
                </div>
                <div class="lanter-field" data-driver-field="mysql">
                    <label>User</label>
                    <input type="text" name="user">
                </div>
                <div class="lanter-field" data-driver-field="mysql">
                    <label>Password</label>
                    <input type="password" name="password">
                </div>
                <button class="lanter-btn" type="submit">{$plugIcon} Connect</button>
            </form>
        </div>
        <script>
            (function () {
                var select = document.getElementById('lanter-login-driver');
                var fields = document.querySelectorAll('[data-driver-field]');

                function sync() {
                    var driver = select.value;
                    fields.forEach(function (field) {
                        field.style.display = field.getAttribute('data-driver-field') === driver ? '' : 'none';
                    });
                }

                select.addEventListener('change', sync);
                sync();
            })();
        </script>
        HTML
    );
}
