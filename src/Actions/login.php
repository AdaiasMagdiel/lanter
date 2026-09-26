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
            $error = 'Não foi possível conectar: ' . $e->getMessage();
        }
    }

    $errorHtml = $error !== null ? '<p style="color:#f43f5e">' . htmlspecialchars($error) . '</p>' : '';

    lanter_render_bare('Entrar', <<<HTML
        <form class="lanter-login" method="post" action="?action=login">
            <h1>Lanter</h1>
            {$errorHtml}
            <div class="lanter-field">
                <label>Driver</label>
                <select name="driver">
                    <option value="sqlite">SQLite</option>
                    <option value="mysql">MySQL / MariaDB</option>
                </select>
            </div>
            <div class="lanter-field">
                <label>Caminho do arquivo (SQLite) ou host (MySQL)</label>
                <input type="text" name="path" placeholder="/caminho/para/banco.sqlite">
                <input type="text" name="host" placeholder="127.0.0.1">
            </div>
            <div class="lanter-field">
                <label>Porta</label>
                <input type="text" name="port" placeholder="3306">
            </div>
            <div class="lanter-field">
                <label>Banco</label>
                <input type="text" name="database">
            </div>
            <div class="lanter-field">
                <label>Usuário</label>
                <input type="text" name="user">
            </div>
            <div class="lanter-field">
                <label>Senha</label>
                <input type="password" name="password">
            </div>
            <button class="lanter-btn" type="submit">Conectar</button>
        </form>
        HTML
    );
}
