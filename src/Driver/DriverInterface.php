<?php

interface Lanter_DriverInterface
{
    public function connect(array $connection): void;

    public function supportsDatabases(): bool;

    /** @return string[] */
    public function listDatabases(): array;

    /** @return string[] */
    public function listTables(): array;

    /** @return string[] */
    public function listViews(): array;

    /** @return array<int, array{name: string, type: string, nullable: bool, default: ?string, key: string, autoIncrement: bool}> */
    public function describeTable(string $table): array;

    /** @return array<int, array<string, mixed>> */
    public function fetchRows(
        string $table,
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $sortColumn = null,
        string $sortDirection = 'asc'
    ): array;

    public function countRows(string $table, ?string $search = null): int;

    /** @return array<string, mixed>|null */
    public function findRow(string $table, string $primaryKey, mixed $primaryValue): ?array;

    public function insertRow(string $table, array $data): void;

    public function updateRow(string $table, string $primaryKey, mixed $primaryValue, array $data): void;

    public function deleteRow(string $table, string $primaryKey, mixed $primaryValue): void;

    /** @param array<int, array{name: string, type: string, nullable: bool, primaryKey: bool, autoIncrement: bool}> $columns */
    public function createTable(string $table, array $columns): void;

    public function createView(string $name, string $query): void;

    /** @return array{columns: string[], rows: array<int, array<string, mixed>>, affected: int} */
    public function runQuery(string $sql): array;

    public function quoteIdentifier(string $identifier): string;

    public function quoteValue(mixed $value): string;

    public function getCreateStatement(string $name, bool $isView): string;
}
