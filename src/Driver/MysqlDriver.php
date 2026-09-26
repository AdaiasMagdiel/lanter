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

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);

        if ($this->database !== '') {
            $dsn .= ';dbname=' . $this->database;
        }

        $this->pdo = new PDO($dsn, $connection['user'] ?? '', $connection['password'] ?? '');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function supportsDatabases(): bool
    {
        return true;
    }

    public function listDatabases(): array
    {
        $stmt = $this->pdo->query('SHOW DATABASES');

        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    public function listTables(): array
    {
        $stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    public function listViews(): array
    {
        $stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");

        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    public function createView(string $name, string $query): void
    {
        $query = rtrim(trim($query), ';');

        if (str_contains($query, ';')) {
            throw new InvalidArgumentException('The view query must be a single SELECT statement.');
        }

        $this->pdo->exec(sprintf('CREATE VIEW %s AS %s', $this->quoteIdentifier($name), $query));
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
                'autoIncrement' => stripos($row['Extra'] ?? '', 'auto_increment') !== false,
            ];
        }

        return $columns;
    }

    public function fetchRows(
        string $table,
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $sortColumn = null,
        string $sortDirection = 'asc'
    ): array {
        [$where, $params] = $this->buildSearchClause($table, $search);
        $orderBy = $sortColumn !== null
            ? ' ORDER BY ' . $this->quoteIdentifier($sortColumn) . ($sortDirection === 'desc' ? ' DESC' : ' ASC')
            : '';

        $sql = sprintf(
            'SELECT * FROM %s%s%s LIMIT %d OFFSET %d',
            $this->quoteIdentifier($table),
            $where,
            $orderBy,
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
            fn (string $column): string => 'CAST(' . $this->quoteIdentifier($column) . ' AS CHAR) LIKE :__search',
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

    /** @param array<int, array{name: string, type: string, nullable: bool, primaryKey: bool, autoIncrement: bool}> $columns */
    public function createTable(string $table, array $columns): void
    {
        $defs = [];
        $primaryKeys = [];

        foreach ($columns as $column) {
            $def = $this->quoteIdentifier($column['name']) . ' ' . $column['type'];

            if (!$column['nullable']) {
                $def .= ' NOT NULL';
            }

            if ($column['autoIncrement']) {
                $def .= ' AUTO_INCREMENT';
            }

            $defs[] = $def;

            if ($column['primaryKey']) {
                $primaryKeys[] = $this->quoteIdentifier($column['name']);
            }
        }

        if ($primaryKeys !== []) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', $primaryKeys) . ')';
        }

        $this->pdo->exec(sprintf('CREATE TABLE %s (%s)', $this->quoteIdentifier($table), implode(', ', $defs)));
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

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function quoteValue(mixed $value): string
    {
        return $value === null ? 'NULL' : $this->pdo->quote((string) $value);
    }

    public function getCreateStatement(string $name, bool $isView): string
    {
        $stmt = $this->pdo->query('SHOW CREATE ' . ($isView ? 'VIEW ' : 'TABLE ') . $this->quoteIdentifier($name));
        $row = $stmt->fetch(PDO::FETCH_NUM);

        return $row[1] ?? '';
    }
}
