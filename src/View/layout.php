<?php

/** @param array<string, int> $tableCounts */
function lanter_render_layout(
    string $title,
    string $content,
    array $tables = [],
    ?string $activeTable = null,
    array $tableCounts = [],
    array $views = []
): void {
    $currentAction = $_GET['action'] ?? 'dashboard';
    $allRelations = [...$tables, ...$views];

    if ($activeTable !== null) {
        $_SESSION['lanter_last_table'] = $activeTable;
    } elseif (isset($_SESSION['lanter_last_table']) && in_array($_SESSION['lanter_last_table'], $allRelations, true)) {
        $activeTable = $_SESSION['lanter_last_table'];
    }

    $connection = $_SESSION['lanter_connection'] ?? null;
    $showDatabaseSwitch = $connection !== null
        && in_array($connection['driver'] ?? '', ['mysql', 'mariadb'], true)
        && ($connection['database'] ?? '') !== '';
    $driverLabels = ['sqlite' => 'SQLite', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'];
    $driverLabel = $driverLabels[$connection['driver'] ?? ''] ?? null;
    $statusLabel = $driverLabel !== null && ($connection['database'] ?? '') !== ''
        ? $driverLabel . ' · ' . $connection['database']
        : $driverLabel;
    $isActiveView = $activeTable !== null && in_array($activeTable, $views, true);
    $activeRowCount = $activeTable !== null && isset($tableCounts[$activeTable])
        ? lanter_format_count($tableCounts[$activeTable]) . ' rows'
        : null;
    ?><!DOCTYPE html>
<html lang="en">
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
<span class="lanter-brand-text">
<span class="lanter-brand-name">Lanter</span>
<?php if ($statusLabel !== null): ?>
<span class="lanter-status-line"><span class="lanter-status-dot"></span><?= htmlspecialchars($statusLabel) ?></span>
<?php endif; ?>
</span>
</div>
<div class="lanter-sidebar-body">
<div class="lanter-sidebar-section-label">
<span>Tables &amp; views (<?= count($allRelations) ?>)</span>
<span class="lanter-sidebar-section-actions">
<a href="?action=table_create" title="New table"><?= lanter_icon('plus') ?></a>
<a href="?action=view_create" title="New view"><?= lanter_icon('eye') ?></a>
</span>
</div>
<?php if (count($allRelations) > 6): ?>
<span class="lanter-search-wrap lanter-sidebar-filter-wrap"><?= lanter_icon('search') ?><input type="text" id="lanter-table-filter" class="lanter-search" placeholder="Filter tables..." autocomplete="off"></span>
<?php endif; ?>
<nav class="lanter-table-list" id="lanter-table-list">
<?php foreach ($tables as $table): ?>
<a class="lanter-table-link<?= $table === $activeTable ? ' active' : '' ?>"
   href="?action=dashboard&amp;table=<?= urlencode($table) ?>"
   data-table-name="<?= htmlspecialchars(strtolower($table)) ?>">
    <?= lanter_icon('table') ?>
    <span><?= htmlspecialchars($table) ?></span>
    <?php if (isset($tableCounts[$table])): ?>
    <span class="lanter-table-count"><?= htmlspecialchars(lanter_format_count($tableCounts[$table])) ?></span>
    <?php endif; ?>
</a>
<?php endforeach; ?>
<?php foreach ($views as $view): ?>
<a class="lanter-table-link<?= $view === $activeTable ? ' active' : '' ?>"
   href="?action=dashboard&amp;table=<?= urlencode($view) ?>"
   data-table-name="<?= htmlspecialchars(strtolower($view)) ?>">
    <?= lanter_icon('eye') ?>
    <span><?= htmlspecialchars($view) ?></span>
    <span class="lanter-view-badge">VIEW</span>
</a>
<?php endforeach; ?>
</nav>
<div class="lanter-sidebar-footer">
<?php if ($showDatabaseSwitch): ?>
<a class="lanter-sidebar-link" href="?action=change_database"><?= lanter_icon('swap') ?><span>Switch database</span></a>
<?php endif; ?>
<a class="lanter-sidebar-link lanter-sidebar-link-danger" href="?action=logout"><?= lanter_icon('logout') ?><span>Sign out</span></a>
</div>
</div>
</aside>
<main class="lanter-main">
<?php if ($activeTable !== null): ?>
<div class="lanter-table-header">
<div class="lanter-table-header-info">
<h1><?= lanter_icon($isActiveView ? 'eye' : 'table') ?><span><?= htmlspecialchars($activeTable) ?></span><?php if ($isActiveView): ?><span class="lanter-view-badge">VIEW</span><?php endif; ?></h1>
<?php if ($activeRowCount !== null): ?><p class="lanter-table-header-meta"><?= htmlspecialchars($activeRowCount) ?></p><?php endif; ?>
</div>
<div class="lanter-table-header-actions">
<a class="lanter-btn lanter-btn-ghost" href="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>" title="Refresh"><?= lanter_icon('refresh') ?><span>Refresh</span></a>
<a class="lanter-btn lanter-btn-ghost" href="?action=table_export&amp;table=<?= urlencode($activeTable) ?>" title="Export SQL"><?= lanter_icon('download') ?><span>Export SQL</span></a>
<?php if (!$isActiveView): ?><a class="lanter-btn" href="?action=row_new&amp;table=<?= urlencode($activeTable) ?>"><?= lanter_icon('plus') ?><span>New Row</span></a><?php endif; ?>
</div>
</div>
<?php endif; ?>
<nav class="lanter-tabs">
<?php if ($activeTable !== null): ?>
<a class="lanter-tab-link<?= $currentAction === 'dashboard' ? ' active' : '' ?>" href="?action=dashboard&amp;table=<?= urlencode($activeTable) ?>"><?= lanter_icon('table') ?><span>Data</span></a>
<a class="lanter-tab-link<?= $currentAction === 'table_structure' ? ' active' : '' ?>" href="?action=table_structure&amp;table=<?= urlencode($activeTable) ?>"><?= lanter_icon('layers') ?><span>Structure</span></a>
<?php endif; ?>
<a class="lanter-tab-link<?= $currentAction === 'sql' ? ' active' : '' ?>" href="?action=sql"><?= lanter_icon('terminal') ?><span>SQL</span></a>
</nav>
<div class="lanter-main-content">
<?= $content ?>
</div>
</main>
</div>
<div class="lanter-modal-overlay" id="lanter-modal-overlay" hidden>
<div id="lanter-modal-box"></div>
</div>
<script>
    (function () {
        var filter = document.getElementById('lanter-table-filter');
        if (!filter) { return; }
        var links = document.querySelectorAll('#lanter-table-list .lanter-table-link');
        filter.addEventListener('input', function () {
            var needle = filter.value.trim().toLowerCase();
            links.forEach(function (link) {
                var match = link.getAttribute('data-table-name').indexOf(needle) !== -1;
                link.style.display = match ? '' : 'none';
            });
        });
    })();

    (function () {
        var overlay = document.getElementById('lanter-modal-overlay');
        var box = document.getElementById('lanter-modal-box');

        function closeModal() {
            overlay.hidden = true;
            box.innerHTML = '';
            document.body.style.overflow = '';
        }

        function openModal(url) {
            fetch(url)
                .then(function (response) { return response.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var panel = doc.querySelector('.lanter-form-panel');

                    if (!panel) {
                        window.location = url;

                        return;
                    }

                    box.innerHTML = '';
                    box.appendChild(panel);
                    overlay.hidden = false;
                    document.body.style.overflow = 'hidden';

                    var cancel = panel.querySelector('.lanter-form-actions .lanter-btn-ghost');

                    if (cancel) {
                        cancel.addEventListener('click', function (event) {
                            event.preventDefault();
                            closeModal();
                        });
                    }
                })
                .catch(function () {
                    window.location = url;
                });
        }

        document.addEventListener('click', function (event) {
            var link = event.target.closest('a[href*="action=row_new"], a[href*="action=row_edit"], a[href*="action=table_create"], a[href*="action=view_create"]');

            if (link) {
                event.preventDefault();
                openModal(link.getAttribute('href'));
            }
        });

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !overlay.hidden) {
                closeModal();
            }
        });
    })();

    (function () {
        var columnCounter = 100;

        document.addEventListener('click', function (event) {
            var addBtn = event.target.closest('.lanter-add-column-btn');

            if (addBtn) {
                var form = addBtn.closest('form');
                var container = form.querySelector('#lanter-columns-container');
                var template = form.querySelector('#lanter-column-row-template');

                if (container && template) {
                    var html = template.innerHTML.split('__INDEX__').join(String(columnCounter++));
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html.trim();
                    container.appendChild(wrapper.firstChild);
                }

                return;
            }

            var removeBtn = event.target.closest('.lanter-remove-column-btn');

            if (removeBtn) {
                var row = removeBtn.closest('.lanter-column-row');
                var container2 = removeBtn.closest('#lanter-columns-container');

                if (row && container2 && container2.children.length > 1) {
                    row.remove();
                }
            }
        });

        document.addEventListener('change', function (event) {
            if (!event.target.classList.contains('lanter-column-type-select')) {
                return;
            }

            var row = event.target.closest('.lanter-column-row');
            var customInput = row ? row.querySelector('.lanter-column-custom-type') : null;

            if (customInput) {
                customInput.style.display = event.target.value === '__custom__' ? '' : 'none';
            }
        });
    })();
</script>
</body>
</html>
<?php
}

function lanter_render_bare(string $title, string $content): void
{
    ?><!DOCTYPE html>
<html lang="en">
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
