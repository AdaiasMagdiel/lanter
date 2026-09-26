<?php

/** @param array<string, string> $actions Label => href */
function lanter_render_error(string $message, array $actions = []): void
{
    $messageHtml = htmlspecialchars($message);

    $actionsHtml = implode('', array_map(
        static fn (string $label, string $href): string => '<a class="lanter-btn lanter-btn-ghost" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>',
        array_keys($actions),
        $actions
    ));

    lanter_render_bare('Error', <<<HTML
        <div class="lanter-login-page">
            <div class="lanter-login lanter-error-card">
                <div class="lanter-login-brand">
                    <span class="lanter-brand-icon lanter-brand-icon-danger">&#9888;</span>
                    <h1>Something went wrong</h1>
                </div>
                <p class="lanter-error-message">{$messageHtml}</p>
                <div class="lanter-form-actions">
                    {$actionsHtml}
                </div>
            </div>
        </div>
        HTML
    );
}
