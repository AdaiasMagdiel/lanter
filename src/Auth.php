<?php

final class Lanter_Auth
{
    public function __construct(private Lanter_Config $config)
    {
    }

    /**
     * Resolves the connection to use for the current request, or null when
     * the user still needs to authenticate / pick a connection.
     *
     * @return array<string, mixed>|null
     */
    public function resolveConnection(): ?array
    {
        return match ($this->config->authMode()) {
            'none' => $this->resolveNoneMode(),
            'delegate' => $this->resolveDelegateMode(),
            default => $this->resolveLoginMode(),
        };
    }

    public function logout(): void
    {
        unset($_SESSION['lanter_connection']);
    }

    private function resolveNoneMode(): ?array
    {
        if (isset($_SESSION['lanter_connection'])) {
            return $_SESSION['lanter_connection'];
        }

        $connections = $this->config->fixedConnections();

        if ($connections === []) {
            return null;
        }

        if (isset($_GET['conn']) && isset($connections[$_GET['conn']])) {
            $_SESSION['lanter_connection'] = $connections[$_GET['conn']];

            return $_SESSION['lanter_connection'];
        }

        $_SESSION['lanter_connection'] = reset($connections);

        return $_SESSION['lanter_connection'];
    }

    private function resolveDelegateMode(): ?array
    {
        $delegate = $this->config->authDelegate();

        if ($delegate === null) {
            return null;
        }

        $connection = $delegate();

        return is_array($connection) ? $connection : null;
    }

    private function resolveLoginMode(): ?array
    {
        return $_SESSION['lanter_connection'] ?? null;
    }

    public function login(array $connection): void
    {
        $_SESSION['lanter_connection'] = $connection;
    }
}
