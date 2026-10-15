<?php

class Lanter_SqliteDriver extends Lanter_PdoDriver
{
    public function connect(array $connection): void
    {
        $path = $connection['path'] ?? $connection['database'] ?? ':memory:';
        $this->pdo = new PDO('sqlite:' . $path);
        $this->configurePdo();
    }

    protected function textCastType(): string
    {
        return 'TEXT';
    }

    public function supportsDatabases(): bool
    {
        return false;
    }

    public function listDatabases(): array
    {
        return [];
    }

    public function listTables(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        return array_column($stmt->fetchAll(), 'name');
    }

    public function listViews(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'view' ORDER BY name");

        return array_column($stmt->fetchAll(), 'name');
    }

    public function describeTable(string $table): array
    {
        $stmt = $this->pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
        $indexed = [];

        foreach ($this->listIndexes($table) as $index) {
            if ($index['primary'] || count($index['columns']) !== 1) {
                continue;
            }

            $kind = $index['unique'] ? 'UNI' : 'MUL';
            $indexed[$index['columns'][0]] = ($indexed[$index['columns'][0]] ?? '') === 'UNI' ? 'UNI' : $kind;
        }

        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $isPk = (int) $row['pk'] > 0;
            $columns[] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => (int) $row['notnull'] === 0,
                'default' => $row['dflt_value'],
                'key' => $isPk ? 'PRI' : ($indexed[$row['name']] ?? ''),
                'autoIncrement' => $isPk && strtolower(trim($row['type'])) === 'integer',
            ];
        }

        return $columns;
    }

    public function listIndexes(string $table): array
    {
        $indexes = [];
        $list = $this->pdo->query('PRAGMA index_list(' . $this->quoteIdentifier($table) . ')')->fetchAll();

        foreach ($list as $row) {
            $info = $this->pdo->query('PRAGMA index_info(' . $this->quoteIdentifier($row['name']) . ')')->fetchAll();
            usort($info, static fn (array $a, array $b): int => $a['seqno'] <=> $b['seqno']);

            $indexes[] = [
                'name' => $row['name'],
                'columns' => array_values(array_filter(array_column($info, 'name'), static fn ($n): bool => $n !== null)),
                'unique' => (int) $row['unique'] === 1,
                'primary' => ($row['origin'] ?? '') === 'pk',
            ];
        }

        return $indexes;
    }

    public function listForeignKeys(string $table): array
    {
        $rows = $this->pdo->query('PRAGMA foreign_key_list(' . $this->quoteIdentifier($table) . ')')->fetchAll();

        return array_map(static fn (array $row): array => [
            'name' => 'fk_' . $row['id'],
            'column' => $row['from'],
            'refTable' => $row['table'],
            'refColumn' => $row['to'] ?? '',
        ], $rows);
    }

    /** @param array<int, array{name: string, type: string, nullable: bool, primaryKey: bool, autoIncrement: bool}> $columns */
    public function createTable(string $table, array $columns): void
    {
        $primaryKeys = array_values(array_filter($columns, static fn (array $c): bool => $c['primaryKey']));
        $singleAutoIncrementPk = count($primaryKeys) === 1
            && $primaryKeys[0]['autoIncrement']
            && strtolower(trim($primaryKeys[0]['type'])) === 'integer';

        $defs = [];
        $tablePkNames = [];

        foreach ($columns as $column) {
            $def = $this->quoteIdentifier($column['name']) . ' ' . $column['type'];

            if ($singleAutoIncrementPk && $column['primaryKey']) {
                $def .= ' PRIMARY KEY AUTOINCREMENT';
            } elseif (!$column['nullable']) {
                $def .= ' NOT NULL';
            }

            $defs[] = $def;

            if (!$singleAutoIncrementPk && $column['primaryKey']) {
                $tablePkNames[] = $this->quoteIdentifier($column['name']);
            }
        }

        if ($tablePkNames !== []) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', $tablePkNames) . ')';
        }

        $this->pdo->exec(sprintf('CREATE TABLE %s (%s)', $this->quoteIdentifier($table), implode(', ', $defs)));
    }

    public function truncateTable(string $table): void
    {
        $this->pdo->exec('DELETE FROM ' . $this->quoteIdentifier($table));
    }

    public function addColumn(string $table, array $column): void
    {
        // SQLite refuses NOT NULL additions without a default on non-empty tables.
        if (!$column['nullable'] && ($column['default'] ?? '') === '' && $this->countRows($table) > 0) {
            throw new InvalidArgumentException('A NOT NULL column needs a default value when the table already has rows.');
        }

        parent::addColumn($table, $column);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function getCreateStatement(string $name, bool $isView): string
    {
        $type = $isView ? 'view' : 'table';
        $stmt = $this->pdo->prepare('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?');
        $stmt->execute([$type, $name]);
        $row = $stmt->fetch();
        $sql = $row['sql'] ?? '';

        if ($isView) {
            return $sql;
        }

        $indexStmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL");
        $indexStmt->execute([$name]);

        foreach ($indexStmt->fetchAll() as $index) {
            $sql .= ";\n" . $index['sql'];
        }

        return $sql;
    }
}
