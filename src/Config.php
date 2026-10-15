<?php

defined('LANTER_VERSION') || define('LANTER_VERSION', '0.2.0');

final class Lanter_Config
{
    public const PAGE_SIZES = [25, 50, 100, 250, 500];

    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function resolve(): self
    {
        $defaults = [
            'auth' => [
                'mode' => 'login',
                'delegate' => null,
            ],
            'connections' => [],
            'per_page' => 25,
            'sql_history' => 20,
        ];

        // The constant is the documented form; the global is the fallback for
        // long-running workers where define() would fail on the second request.
        $external = defined('LANTER_CONFIG')
            ? LANTER_CONFIG
            : ($GLOBALS['LANTER_CONFIG'] ?? []);

        return new self(array_replace_recursive($defaults, is_array($external) ? $external : []));
    }

    public function authMode(): string
    {
        return $this->data['auth']['mode'];
    }

    public function authDelegate(): ?callable
    {
        return $this->data['auth']['delegate'];
    }

    /** @return array<string, array<string, mixed>> */
    public function fixedConnections(): array
    {
        return $this->data['connections'];
    }

    public function perPage(): int
    {
        $perPage = (int) $this->data['per_page'];

        return in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;
    }

    public function sqlHistorySize(): int
    {
        return max(0, (int) $this->data['sql_history']);
    }
}
