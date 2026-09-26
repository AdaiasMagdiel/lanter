<?php

interface Lanter_DriverInterface
{
    public function connect(array $connection): void;

    /** @return string[] */
    public function listTables(): array;

    /** @return array<int, array{name: string, type: string, nullable: bool, default: ?string, key: string}> */
    public function describeTable(string $table): array;

    /** @return array<int, array<string, mixed>> */
    public function fetchRows(string $table, int $limit, int $offset, ?string $search = null): array;

    public function countRows(string $table, ?string $search = null): int;

    /** @return array<string, mixed>|null */
    public function findRow(string $table, string $primaryKey, mixed $primaryValue): ?array;

    public function insertRow(string $table, array $data): void;

    public function updateRow(string $table, string $primaryKey, mixed $primaryValue, array $data): void;

    public function deleteRow(string $table, string $primaryKey, mixed $primaryValue): void;

    /** @return array{columns: string[], rows: array<int, array<string, mixed>>, affected: int} */
    public function runQuery(string $sql): array;
}
