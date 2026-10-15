<?php

class Lanter_MysqlDriver extends Lanter_PdoDriver
{
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
        $this->configurePdo();
    }

    protected function textCastType(): string
    {
        return 'CHAR';
    }

    protected function emptyInsertClause(): string
    {
        return '() VALUES ()';
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

    public function describeTable(string $table): array
    {
        $stmt = $this->pdo->query('DESCRIBE ' . $this->quoteIdentifier($table));

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

    public function listIndexes(string $table): array
    {
        $rows = $this->pdo->query('SHOW INDEX FROM ' . $this->quoteIdentifier($table))->fetchAll();
        $indexes = [];

        foreach ($rows as $row) {
            $name = $row['Key_name'];
            $indexes[$name] ??= [
                'name' => $name,
                'columns' => [],
                'unique' => (int) $row['Non_unique'] === 0,
                'primary' => $name === 'PRIMARY',
            ];
            $indexes[$name]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
        }

        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }

        return array_values($indexes);
    }

    public function listForeignKeys(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT CONSTRAINT_NAME AS name, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS ref_table, REFERENCED_COLUMN_NAME AS ref_column
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION'
        );
        $stmt->execute([$table]);

        return array_map(static fn (array $row): array => [
            'name' => $row['name'],
            'column' => $row['col'],
            'refTable' => $row['ref_table'],
            'refColumn' => $row['ref_column'],
        ], $stmt->fetchAll());
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

    public function renameTable(string $from, string $to): void
    {
        $this->pdo->exec(sprintf('RENAME TABLE %s TO %s', $this->quoteIdentifier($from), $this->quoteIdentifier($to)));
    }

    public function dropIndex(string $table, string $name): void
    {
        $this->pdo->exec(sprintf('DROP INDEX %s ON %s', $this->quoteIdentifier($name), $this->quoteIdentifier($table)));
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function getCreateStatement(string $name, bool $isView): string
    {
        $stmt = $this->pdo->query('SHOW CREATE ' . ($isView ? 'VIEW ' : 'TABLE ') . $this->quoteIdentifier($name));
        $row = $stmt->fetch(PDO::FETCH_NUM);

        return $row[1] ?? '';
    }
}
