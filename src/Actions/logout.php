<?php

function lanter_action_logout(Lanter_Config $config, Lanter_Auth $auth, ?array $connection): void
{
    $auth->logout();
    lanter_redirect('login');
}
