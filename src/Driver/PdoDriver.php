<?php

/** Shared PDO behaviour; concrete drivers only supply the dialect-specific pieces. */
abstract class Lanter_PdoDriver implements Lanter_DriverInterface
{
    protected PDO $pdo;

    /** SQL type used when casting a column to text for the global search. */
    abstract protected function textCastType(): string;

    protected function likeOperator(): string
    {
        return 'LIKE';
    }

    protected function configurePdo(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function fetchRows(
        string $table,
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $sortColumn = null,
        string $sortDirection = 'asc',
        array $filters = []
    ): array {
        [$where, $params] = $this->buildWhere($table, $search, $filters);
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

    public function countRows(string $table, ?string $search = null, array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($table, $search, $filters);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM ' . $this->quoteIdentifier($table) . $where);
        $stmt->execute($params);

        return (int) $stmt->fetch()['total'];
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function buildWhere(string $table, ?string $search, array $filters): array
    {
        $conditions = [];
        $params = [];
        $columns = ($search !== null && $search !== '') || $filters !== []
            ? array_column($this->describeTable($table), 'name')
            : [];

        if ($search !== null && $search !== '' && $columns !== []) {
            $like = $this->likeOperator();
            $searchConditions = array_map(
                fn (string $column): string => 'CAST(' . $this->quoteIdentifier($column) . ' AS ' . $this->textCastType() . ') ' . $like . ' ?',
                $columns
            );
            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            $params = array_merge($params, array_fill(0, count($columns), '%' . $search . '%'));
        }

        foreach ($filters as $column => $expression) {
            if (!in_array($column, $columns, true) || trim((string) $expression) === '') {
                continue;
            }

            [$sql, $values] = $this->parseFilter($this->quoteIdentifier($column), trim((string) $expression));
            $conditions[] = $sql;
            $params = array_merge($params, $values);
        }

        if ($conditions === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Filter grammar: "null" / "!null", a comparison operator followed by a value
     * (=, !=, <>, <, <=, >, >=), or a bare value which becomes a contains-match.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function parseFilter(string $quotedColumn, string $expression): array
    {
        $lower = strtolower($expression);

        if ($lower === 'null' || $lower === 'is null') {
            return [$quotedColumn . ' IS NULL', []];
        }

        if ($lower === '!null' || $lower === 'is not null' || $lower === 'not null') {
            return [$quotedColumn . ' IS NOT NULL', []];
        }

        if (preg_match('/^(>=|<=|<>|!=|=|<|>)\s*(.*)$/s', $expression, $m) === 1 && $m[2] !== '') {
            $operator = $m[1] === '<>' ? '!=' : $m[1];

            return [$quotedColumn . ' ' . $operator . ' ?', [$m[2]]];
        }

        return [
            'CAST(' . $quotedColumn . ' AS ' . $this->textCastType() . ') ' . $this->likeOperator() . ' ?',
            ['%' . $expression . '%'],
        ];
    }

    public function findRow(string $table, string $primaryKey, mixed $primaryValue): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = ? LIMIT 1',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($primaryKey)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$primaryValue]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function insertRow(string $table, array $data): void
    {
        $columns = array_keys($data);

        if ($columns === []) {
            $this->pdo->exec('INSERT INTO ' . $this->quoteIdentifier($table) . ' ' . $this->emptyInsertClause());

            return;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
    }

    protected function emptyInsertClause(): string
    {
        return 'DEFAULT VALUES';
    }

    public function updateRow(string $table, string $primaryKey, mixed $primaryValue, array $data): void
    {
        if ($data === []) {
            return;
        }

        $assignments = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column) . ' = ?',
            array_keys($data)
        ));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->quoteIdentifier($table),
            $assignments,
            $this->quoteIdentifier($primaryKey)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...array_values($data), $primaryValue]);
    }

    public function deleteRow(string $table, string $primaryKey, mixed $primaryValue): void
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($primaryKey)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$primaryValue]);
    }

    public function createView(string $name, string $query): void
    {
        $query = rtrim(trim($query), ';');

        if (str_contains($query, ';')) {
            throw new InvalidArgumentException('The view query must be a single SELECT statement.');
        }

        $this->pdo->exec(sprintf('CREATE VIEW %s AS %s', $this->quoteIdentifier($name), $query));
    }

    public function dropTable(string $table): void
    {
        $this->pdo->exec('DROP TABLE ' . $this->quoteIdentifier($table));
    }

    public function dropView(string $name): void
    {
        $this->pdo->exec('DROP VIEW ' . $this->quoteIdentifier($name));
    }

    public function truncateTable(string $table): void
    {
        $this->pdo->exec('TRUNCATE TABLE ' . $this->quoteIdentifier($table));
    }

    public function renameTable(string $from, string $to): void
    {
        $this->pdo->exec(sprintf('ALTER TABLE %s RENAME TO %s', $this->quoteIdentifier($from), $this->quoteIdentifier($to)));
    }

    public function addColumn(string $table, array $column): void
    {
        $this->pdo->exec(sprintf(
            'ALTER TABLE %s ADD COLUMN %s',
            $this->quoteIdentifier($table),
            $this->columnDefinition($column)
        ));
    }

    /** @param array{name: string, type: string, nullable: bool, default: ?string} $column */
    protected function columnDefinition(array $column): string
    {
        $def = $this->quoteIdentifier($column['name']) . ' ' . $column['type'];

        if (!$column['nullable']) {
            $def .= ' NOT NULL';
        }

        $default = $column['default'] ?? null;

        if ($default !== null && $default !== '') {
            $def .= ' DEFAULT ' . $this->defaultExpression($default);
        }

        return $def;
    }

    /** Keywords and numbers are emitted raw so CURRENT_TIMESTAMP and 0 behave as expected. */
    protected function defaultExpression(string $default): string
    {
        if (preg_match('/^(current_timestamp|current_date|current_time|now\(\)|null|true|false|-?\d+(\.\d+)?)$/i', $default) === 1) {
            return $default;
        }

        return $this->quoteValue($default);
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->pdo->exec(sprintf('ALTER TABLE %s DROP COLUMN %s', $this->quoteIdentifier($table), $this->quoteIdentifier($column)));
    }

    public function renameColumn(string $table, string $from, string $to): void
    {
        $this->pdo->exec(sprintf(
            'ALTER TABLE %s RENAME COLUMN %s TO %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($from),
            $this->quoteIdentifier($to)
        ));
    }

    public function createIndex(string $table, string $name, array $columns, bool $unique): void
    {
        $this->pdo->exec(sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $this->quoteIdentifier($name),
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns))
        ));
    }

    public function dropIndex(string $table, string $name): void
    {
        $this->pdo->exec('DROP INDEX ' . $this->quoteIdentifier($name));
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        // MySQL auto-commits on DDL, which silently ends the transaction.
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
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
        $columns = [];

        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = $meta['name'] ?? ('column' . ($i + 1));
        }

        if ($rows !== [] && count($columns) !== count($rows[0])) {
            $columns = array_keys($rows[0]);
        }

        return ['columns' => $columns, 'rows' => $rows, 'affected' => count($rows)];
    }

    public function quoteValue(mixed $value): string
    {
        return $value === null ? 'NULL' : $this->pdo->quote((string) $value);
    }
}
