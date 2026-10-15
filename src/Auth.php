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

    /** Whether a null connection means "denied" (delegate) rather than "not signed in yet". */
    public function deniesWhenUnresolved(): bool
    {
        return $this->config->authMode() === 'delegate';
    }

    public function logout(): void
    {
        unset($_SESSION['lanter_connection']);
    }

    private function resolveNoneMode(): ?array
    {
        $connections = $this->config->fixedConnections();

        if ($connections === []) {
            return null;
        }

        if (isset($_GET['conn']) && isset($connections[$_GET['conn']])) {
            $_SESSION['lanter_connection'] = $connections[$_GET['conn']];

            return $_SESSION['lanter_connection'];
        }

        if (isset($_SESSION['lanter_connection'])) {
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

        if (!is_array($connection)) {
            return null;
        }

        // Database switching (MySQL/Postgres) needs per-session state even when
        // the delegate owns the credentials; only the chosen database is kept.
        if (isset($_SESSION['lanter_delegate_database']) && ($connection['database'] ?? '') === '') {
            $connection['database'] = $_SESSION['lanter_delegate_database'];
        }

        return $connection;
    }

    private function resolveLoginMode(): ?array
    {
        return $_SESSION['lanter_connection'] ?? null;
    }

    public function login(array $connection): void
    {
        if ($this->config->authMode() === 'delegate') {
            $_SESSION['lanter_delegate_database'] = $connection['database'] ?? '';

            return;
        }

        $_SESSION['lanter_connection'] = $connection;
    }
}
