<?php

class Lanter_PgsqlDriver extends Lanter_PdoDriver
{
    public function connect(array $connection): void
    {
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? 5432;
        // Postgres cannot connect without a database; "postgres" always exists.
        $database = ($connection['database'] ?? '') !== '' ? $connection['database'] : 'postgres';

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);

        $this->pdo = new PDO($dsn, $connection['user'] ?? '', $connection['password'] ?? '');
        $this->configurePdo();
    }

    protected function textCastType(): string
    {
        return 'TEXT';
    }

    protected function likeOperator(): string
    {
        return 'ILIKE';
    }

    public function supportsDatabases(): bool
    {
        return true;
    }

    public function listDatabases(): array
    {
        $stmt = $this->pdo->query('SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname');

        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    public function listTables(): array
    {
        $stmt = $this->pdo->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename');

        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    public function listViews(): array
    {
        $stmt = $this->pdo->query('SELECT viewname FROM pg_views WHERE schemaname = current_schema() ORDER BY viewname');

        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    public function describeTable(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.attname AS name,
                    format_type(a.atttypid, a.atttypmod) AS type,
                    a.attnotnull AS not_null,
                    pg_get_expr(d.adbin, d.adrelid) AS default_expr,
                    a.attidentity AS identity,
                    COALESCE((SELECT bool_or(i.indisprimary) FROM pg_index i WHERE i.indrelid = a.attrelid AND a.attnum = ANY(i.indkey)), false) AS is_pk,
                    COALESCE((SELECT bool_or(i.indisunique) FROM pg_index i WHERE i.indrelid = a.attrelid AND a.attnum = ANY(i.indkey) AND array_length(i.indkey, 1) = 1), false) AS is_unique,
                    EXISTS(SELECT 1 FROM pg_index i WHERE i.indrelid = a.attrelid AND a.attnum = ANY(i.indkey)) AS is_indexed
             FROM pg_attribute a
             LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE a.attrelid = CAST(? AS regclass) AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum'
        );
        $stmt->execute([$this->quoteIdentifier($table)]);

        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $default = $row['default_expr'];
            $isSerial = $default !== null && str_starts_with((string) $default, 'nextval(');
            $isPk = $this->toBool($row['is_pk']);

            $columns[] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => !$this->toBool($row['not_null']),
                'default' => $isSerial ? null : $default,
                'key' => $isPk ? 'PRI' : ($this->toBool($row['is_unique']) ? 'UNI' : ($this->toBool($row['is_indexed']) ? 'MUL' : '')),
                'autoIncrement' => $isSerial || ($row['identity'] ?? '') !== '',
            ];
        }

        return $columns;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }

    public function listIndexes(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.relname AS name, ix.indisunique AS is_unique, ix.indisprimary AS is_primary,
                    (SELECT string_agg(a.attname, \',\' ORDER BY k.ord)
                       FROM unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord)
                       JOIN pg_attribute a ON a.attrelid = ix.indrelid AND a.attnum = k.attnum) AS cols
             FROM pg_index ix
             JOIN pg_class i ON i.oid = ix.indexrelid
             WHERE ix.indrelid = CAST(? AS regclass)
             ORDER BY ix.indisprimary DESC, i.relname'
        );
        $stmt->execute([$this->quoteIdentifier($table)]);

        return array_map(fn (array $row): array => [
            'name' => $row['name'],
            'columns' => $row['cols'] !== null ? explode(',', $row['cols']) : [],
            'unique' => $this->toBool($row['is_unique']),
            'primary' => $this->toBool($row['is_primary']),
        ], $stmt->fetchAll());
    }

    public function listForeignKeys(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.conname AS name, a.attname AS col, cl.relname AS ref_table, af.attname AS ref_column
             FROM pg_constraint c
             JOIN pg_class cl ON cl.oid = c.confrelid
             JOIN unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord) ON true
             JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
             JOIN unnest(c.confkey) WITH ORDINALITY AS fk(attnum, ord) ON fk.ord = k.ord
             JOIN pg_attribute af ON af.attrelid = c.confrelid AND af.attnum = fk.attnum
             WHERE c.contype = \'f\' AND c.conrelid = CAST(? AS regclass)
             ORDER BY c.conname, k.ord'
        );
        $stmt->execute([$this->quoteIdentifier($table)]);

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

            if ($column['autoIncrement']) {
                $def .= ' GENERATED BY DEFAULT AS IDENTITY';
            } elseif (!$column['nullable']) {
                $def .= ' NOT NULL';
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

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /** Postgres has no SHOW CREATE, so the statement is rebuilt from the catalog. */
    public function getCreateStatement(string $name, bool $isView): string
    {
        if ($isView) {
            $stmt = $this->pdo->prepare('SELECT pg_get_viewdef(CAST(? AS regclass), true)');
            $stmt->execute([$this->quoteIdentifier($name)]);

            return 'CREATE VIEW ' . $this->quoteIdentifier($name) . " AS\n" . rtrim((string) $stmt->fetchColumn(), "; \n");
        }

        $defs = [];
        $primaryKeys = [];

        foreach ($this->describeTable($name) as $column) {
            $def = $this->quoteIdentifier($column['name']) . ' ' . $column['type'];

            if ($column['autoIncrement']) {
                $def .= ' GENERATED BY DEFAULT AS IDENTITY';
            }

            if (!$column['nullable']) {
                $def .= ' NOT NULL';
            }

            if ($column['default'] !== null) {
                $def .= ' DEFAULT ' . $column['default'];
            }

            $defs[] = '    ' . $def;

            if ($column['key'] === 'PRI') {
                $primaryKeys[] = $this->quoteIdentifier($column['name']);
            }
        }

        if ($primaryKeys !== []) {
            $defs[] = '    PRIMARY KEY (' . implode(', ', $primaryKeys) . ')';
        }

        foreach ($this->listForeignKeys($name) as $fk) {
            $defs[] = sprintf(
                '    CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
                $this->quoteIdentifier($fk['name']),
                $this->quoteIdentifier($fk['column']),
                $this->quoteIdentifier($fk['refTable']),
                $this->quoteIdentifier($fk['refColumn'])
            );
        }

        $sql = 'CREATE TABLE ' . $this->quoteIdentifier($name) . " (\n" . implode(",\n", $defs) . "\n)";

        $indexStmt = $this->pdo->prepare(
            'SELECT pg_get_indexdef(ix.indexrelid) FROM pg_index ix WHERE ix.indrelid = CAST(? AS regclass) AND NOT ix.indisprimary'
        );
        $indexStmt->execute([$this->quoteIdentifier($name)]);

        foreach ($indexStmt->fetchAll(PDO::FETCH_NUM) as [$indexSql]) {
            $sql .= ";\n" . $indexSql;
        }

        return $sql;
    }
}
