<?php

class Lanter_MysqlDriver implements Lanter_DriverInterface
{
    private PDO $pdo;
    private string $database;

    public function connect(array $connection): void
    {
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? 3306;
        $this->database = $connection['database'] ?? '';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $this->database);

        $this->pdo = new PDO($dsn, $connection['user'] ?? '', $connection['password'] ?? '');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function listTables(): array
    {
        $stmt = $this->pdo->query('SHOW TABLES');

        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    public function describeTable(string $table): array
    {
        $stmt = $this->pdo->prepare('DESCRIBE ' . $this->quoteIdentifier($table));
        $stmt->execute();

        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $columns[] = [
                'name' => $row['Field'],
                'type' => $row['Type'],
                'nullable' => $row['Null'] === 'YES',
                'default' => $row['Default'],
                'key' => $row['Key'],
            ];
        }

        return $columns;
    }

    public function fetchRows(string $table, int $limit, int $offset): array
    {
        $sql = sprintf(
            'SELECT * FROM %s LIMIT %d OFFSET %d',
            $this->quoteIdentifier($table),
            $limit,
            $offset
        );

        return $this->pdo->query($sql)->fetchAll();
    }

    public function countRows(string $table): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) AS total FROM ' . $this->quoteIdentifier($table));

        return (int) $stmt->fetch()['total'];
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
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
