<?php

class Lanter_SqliteDriver implements Lanter_DriverInterface
{
    private PDO $pdo;

    public function connect(array $connection): void
    {
        $path = $connection['path'] ?? $connection['database'] ?? ':memory:';
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function listTables(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        return array_column($stmt->fetchAll(), 'name');
    }

    public function describeTable(string $table): array
    {
        $stmt = $this->pdo->prepare('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
        $stmt->execute();

        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $columns[] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => $row['notnull'] === 0,
                'default' => $row['dflt_value'],
                'key' => $row['pk'] > 0 ? 'PRI' : '',
            ];
        }

        return $columns;
    }

    public function fetchRows(string $table, int $limit, int $offset, ?string $search = null): array
    {
        [$where, $params] = $this->buildSearchClause($table, $search);

        $sql = sprintf(
            'SELECT * FROM %s%s LIMIT %d OFFSET %d',
            $this->quoteIdentifier($table),
            $where,
            $limit,
            $offset
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function countRows(string $table, ?string $search = null): int
    {
        [$where, $params] = $this->buildSearchClause($table, $search);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM ' . $this->quoteIdentifier($table) . $where);
        $stmt->execute($params);

        return (int) $stmt->fetch()['total'];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function buildSearchClause(string $table, ?string $search): array
    {
        if ($search === null || $search === '') {
            return ['', []];
        }

        $columns = array_column($this->describeTable($table), 'name');

        if ($columns === []) {
            return ['', []];
        }

        $conditions = array_map(
            fn (string $column): string => 'CAST(' . $this->quoteIdentifier($column) . ' AS TEXT) LIKE :__search',
            $columns
        );

        return [' WHERE ' . implode(' OR ', $conditions), ['__search' => '%' . $search . '%']];
    }

    public function findRow(string $table, string $primaryKey, mixed $primaryValue): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :__pk LIMIT 1',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($primaryKey)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['__pk' => $primaryValue]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function insertRow(string $table, array $data): void
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function updateRow(string $table, string $primaryKey, mixed $primaryValue, array $data): void
    {
        $assignments = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column) . ' = :' . $column,
            array_keys($data)
        ));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :__pk',
            $this->quoteIdentifier($table),
            $assignments,
            $this->quoteIdentifier($primaryKey)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$data, '__pk' => $primaryValue]);
    }

    public function deleteRow(string $table, string $primaryKey, mixed $primaryValue): void
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = :__pk',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($primaryKey)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['__pk' => $primaryValue]);
    }

    public function runQuery(string $sql): array
    {
        $stmt = $this->pdo->query($sql);

        if ($stmt === false) {
            return ['columns' => [], 'rows' => [], 'affected' => 0];
        }

        if ($stmt->columnCount() === 0) {
            return ['columns' => [], 'rows' => [], 'affected' => $stmt->rowCount()];
        }

        $rows = $stmt->fetchAll();
        $columns = $rows === [] ? [] : array_keys($rows[0]);

        return ['columns' => $columns, 'rows' => $rows, 'affected' => count($rows)];
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
