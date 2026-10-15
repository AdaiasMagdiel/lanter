<?php

function lanter_action_login(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $driver = $_POST['driver'] ?? 'sqlite';
        $defaultPort = $driver === 'pgsql' ? 5432 : 3306;

        $candidate = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'path' => $_POST['path'] ?? '']
            : [
                'driver' => in_array($driver, ['mysql', 'mariadb', 'pgsql'], true) ? $driver : 'mysql',
                'host' => ($_POST['host'] ?? '') !== '' ? $_POST['host'] : '127.0.0.1',
                'port' => (int) (($_POST['port'] ?? '') !== '' ? $_POST['port'] : $defaultPort),
                'database' => $_POST['database'] ?? '',
                'user' => $_POST['user'] ?? '',
                'password' => $_POST['password'] ?? '',
            ];

        if (isset($_POST['read_only'])) {
            $candidate['read_only'] = true;
        }

        try {
            lanter_make_driver($candidate);
            $auth->login($candidate);
            lanter_redirect('dashboard');
        } catch (Lanter_Halt $halt) {
            throw $halt;
        } catch (Throwable $e) {
            $error = 'Could not connect: ' . $e->getMessage();
        }
    }

    $errorHtml = $error !== null ? '<p class="lanter-form-error">' . htmlspecialchars($error) . '</p>' : '';
    $plugIcon = lanter_icon('check');
    $csrf = lanter_csrf_field();
    $version = htmlspecialchars(LANTER_VERSION);

    lanter_render_bare('Sign in', <<<HTML
        <div class="lanter-login-page">
            <form class="lanter-login" method="post" action="?action=login">
                {$csrf}
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
                        <option value="pgsql">PostgreSQL</option>
                    </select>
                </div>
                <div class="lanter-field" data-driver-field="sqlite">
                    <label>File path (SQLite)</label>
                    <input type="text" name="path" placeholder="/path/to/database.sqlite">
                </div>
                <div class="lanter-field" data-driver-field="mysql pgsql">
                    <label>Host</label>
                    <input type="text" name="host" placeholder="127.0.0.1">
                </div>
                <div class="lanter-field" data-driver-field="mysql pgsql">
                    <label>Port</label>
                    <input type="text" name="port" id="lanter-login-port" placeholder="3306">
                </div>
                <div class="lanter-field" data-driver-field="mysql pgsql">
                    <label>Database <span class="lanter-hint">(optional, pick later)</span></label>
                    <input type="text" name="database">
                </div>
                <div class="lanter-field" data-driver-field="mysql pgsql">
                    <label>User</label>
                    <input type="text" name="user">
                </div>
                <div class="lanter-field" data-driver-field="mysql pgsql">
                    <label>Password</label>
                    <input type="password" name="password">
                </div>
                <label class="lanter-checkbox-row"><input type="checkbox" name="read_only" value="1"><span>Open read-only</span></label>
                <button class="lanter-btn" type="submit">{$plugIcon} Connect</button>
                <p class="lanter-login-version">Lanter v{$version}</p>
            </form>
        </div>
        <script>
            (function () {
                var select = document.getElementById('lanter-login-driver');
                var port = document.getElementById('lanter-login-port');
                var fields = document.querySelectorAll('[data-driver-field]');

                function sync() {
                    var driver = select.value;
                    fields.forEach(function (field) {
                        var drivers = field.getAttribute('data-driver-field').split(' ');
                        field.style.display = drivers.indexOf(driver) !== -1 ? '' : 'none';
                    });
                    port.placeholder = driver === 'pgsql' ? '5432' : '3306';
                }

                select.addEventListener('change', sync);
                sync();
            })();
        </script>
        HTML
    );
}
