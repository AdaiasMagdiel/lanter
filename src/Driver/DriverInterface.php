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

    /** @return array<int, array{name: string, columns: string[], unique: bool, primary: bool}> */
    public function listIndexes(string $table): array;

    /** @return array<int, array{name: string, column: string, refTable: string, refColumn: string}> */
    public function listForeignKeys(string $table): array;

    /**
     * @param array<string, string> $filters column => filter expression (see Lanter_PdoDriver::parseFilter)
     * @return array<int, array<string, mixed>>
     */
    public function fetchRows(
        string $table,
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $sortColumn = null,
        string $sortDirection = 'asc',
        array $filters = []
    ): array;

    /** @param array<string, string> $filters */
    public function countRows(string $table, ?string $search = null, array $filters = []): int;

    /** @return array<string, mixed>|null */
    public function findRow(string $table, string $primaryKey, mixed $primaryValue): ?array;

    public function insertRow(string $table, array $data): void;

    public function updateRow(string $table, string $primaryKey, mixed $primaryValue, array $data): void;

    public function deleteRow(string $table, string $primaryKey, mixed $primaryValue): void;

    /** @param array<int, array{name: string, type: string, nullable: bool, primaryKey: bool, autoIncrement: bool}> $columns */
    public function createTable(string $table, array $columns): void;

    public function createView(string $name, string $query): void;

    public function dropTable(string $table): void;

    public function dropView(string $name): void;

    public function truncateTable(string $table): void;

    public function renameTable(string $from, string $to): void;

    /** @param array{name: string, type: string, nullable: bool, default: ?string} $column */
    public function addColumn(string $table, array $column): void;

    public function dropColumn(string $table, string $column): void;

    public function renameColumn(string $table, string $from, string $to): void;

    /** @param string[] $columns */
    public function createIndex(string $table, string $name, array $columns, bool $unique): void;

    public function dropIndex(string $table, string $name): void;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /** @return array{columns: string[], rows: array<int, array<string, mixed>>, affected: int} */
    public function runQuery(string $sql): array;

    public function quoteIdentifier(string $identifier): string;

    public function quoteValue(mixed $value): string;

    public function getCreateStatement(string $name, bool $isView): string;
}
