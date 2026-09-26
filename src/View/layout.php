<?php

function lanter_render_layout(string $title, string $content, array $tables = [], ?string $activeTable = null): void
{
    $currentAction = $_GET['action'] ?? 'dashboard';
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> · Lanter</title>
<style><?= lanter_css() ?></style>
</head>
<body>
<div class="lanter-shell">
<aside class="lanter-sidebar">
<div class="lanter-brand">
<span class="lanter-brand-icon">&#128161;</span>
<span class="lanter-brand-name">Lanter</span>
</div>
<nav class="lanter-table-list">
<?php foreach ($tables as $table): ?>
<a class="lanter-table-link<?= $table === $activeTable ? ' active' : '' ?>"
   href="?action=dashboard&amp;table=<?= urlencode($table) ?>">
    <?= htmlspecialchars($table) ?>
</a>
<?php endforeach; ?>
</nav>
<a class="lanter-logout" href="?action=logout">Sair</a>
</aside>
<main class="lanter-main">
<nav class="lanter-tabs">
<?php if ($activeTable !== null): ?>
<a class="lanter-tab-link<?= $currentAction === 'dashboard' ? ' active' : '' ?>" href="?action=dashboard&amp;table=<?= urlencode($activeTable) ?>">Dados</a>
<a class="lanter-tab-link<?= $currentAction === 'table_structure' ? ' active' : '' ?>" href="?action=table_structure&amp;table=<?= urlencode($activeTable) ?>">Estrutura</a>
<?php endif; ?>
<a class="lanter-tab-link<?= $currentAction === 'sql' ? ' active' : '' ?>" href="?action=sql">SQL</a>
</nav>
<?= $content ?>
</main>
</div>
</body>
</html>
<?php
}

function lanter_render_bare(string $title, string $content): void
{
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> · Lanter</title>
<style><?= lanter_css() ?></style>
</head>
<body>
<?= $content ?>
</body>
</html>
<?php
}
