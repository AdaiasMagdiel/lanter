<?php

final class Lanter_Config
{
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
            'allow_ui_connections' => true,
            'data_file' => __DIR__ . '/lanter_data.sqlite',
        ];

        $external = defined('LANTER_CONFIG') ? LANTER_CONFIG : [];

        return new self(array_replace_recursive($defaults, $external));
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

    public function allowUiConnections(): bool
    {
        return $this->data['allow_ui_connections'];
    }

    public function dataFile(): string
    {
        return $this->data['data_file'];
    }
}
