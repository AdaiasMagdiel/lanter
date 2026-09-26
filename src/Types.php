<?php

/** @return array{kind: string, step: ?string, options: ?array<int, string>} */
function lanter_column_input_spec(string $type): array
{
    $normalized = strtolower(trim($type));

    if (preg_match('/^enum\((.*)\)$/', $normalized, $matches) === 1) {
        return ['kind' => 'select', 'step' => null, 'options' => lanter_parse_enum_options($matches[1])];
    }

    if (preg_match('/tinyint\(1\)|\bbool(ean)?\b/', $normalized) === 1) {
        return ['kind' => 'checkbox', 'step' => null, 'options' => null];
    }

    if (preg_match('/\b(int|integer|serial|smallint|mediumint|bigint|year)\b/', $normalized) === 1) {
        return ['kind' => 'number', 'step' => '1', 'options' => null];
    }

    if (preg_match('/\b(decimal|numeric|float|double|real)\b/', $normalized) === 1) {
        return ['kind' => 'number', 'step' => 'any', 'options' => null];
    }

    if (preg_match('/\bdatetime\b|\btimestamp\b/', $normalized) === 1) {
        return ['kind' => 'datetime-local', 'step' => null, 'options' => null];
    }

    if (preg_match('/\bdate\b/', $normalized) === 1) {
        return ['kind' => 'date', 'step' => null, 'options' => null];
    }

    if (preg_match('/\btime\b/', $normalized) === 1) {
        return ['kind' => 'time', 'step' => null, 'options' => null];
    }

    if (preg_match('/\b(text|blob|json)\b/', $normalized) === 1) {
        return ['kind' => 'textarea', 'step' => null, 'options' => null];
    }

    return ['kind' => 'text', 'step' => null, 'options' => null];
}

/** @return array<int, string> */
function lanter_parse_enum_options(string $raw): array
{
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $raw, $matches);

    return array_map(
        static fn (string $value): string => str_replace(["\\'", '\\\\'], ["'", '\\'], $value),
        $matches[1]
    );
}

function lanter_format_value_for_display(string $kind, mixed $value): string
{
    if ($value === null) {
        return '';
    }

    $value = (string) $value;

    if ($kind === 'datetime-local' && $value !== '') {
        return str_replace(' ', 'T', substr($value, 0, 16));
    }

    return $value;
}

function lanter_format_value_for_storage(string $kind, string $value): string
{
    if ($kind === 'datetime-local' && $value !== '') {
        return str_replace('T', ' ', $value) . ':00';
    }

    return $value;
}

function lanter_is_truthy_value(mixed $value): bool
{
    if ($value === null) {
        return false;
    }

    $value = is_string($value) ? strtolower($value) : $value;

    return !in_array($value, ['0', 0, false, ''], true);
}
