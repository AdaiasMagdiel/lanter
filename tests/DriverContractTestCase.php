<?php

use PHPUnit\Framework\TestCase;

/**
 * Exercises every Lanter_DriverInterface method against a live server. Subclasses
 * provide the connection from an environment variable and skip when it is absent.
 */
abstract class DriverContractTestCase extends TestCase
{
    protected Lanter_DriverInterface $driver;

    abstract protected function connectionFromEnv(): ?array;

    abstract protected function driverKind(): string;

    /** Column types in the dialect: [int pk, text, decimal]. */
    abstract protected function types(): array;

    protected function setUp(): void
    {
        $connection = $this->connectionFromEnv();

        if ($connection === null) {
            self::markTestSkipped('Set the DSN environment variable to run ' . static::class);
        }

        $this->driver = lanter_make_driver($connection);
        $this->dropAll();

        [$int, $text, $decimal] = $this->types();

        $this->driver->createTable('lt_authors', [
            ['name' => 'id', 'type' => $int, 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true],
            ['name' => 'name', 'type' => $text, 'nullable' => false, 'primaryKey' => false, 'autoIncrement' => false],
            ['name' => 'email', 'type' => $text, 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false],
        ]);
        $this->driver->createTable('lt_books', [
            ['name' => 'id', 'type' => $int, 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true],
            ['name' => 'author_id', 'type' => $int, 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false],
            ['name' => 'title', 'type' => $text, 'nullable' => false, 'primaryKey' => false, 'autoIncrement' => false],
            ['name' => 'price', 'type' => $decimal, 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false],
            ['name' => 'notes', 'type' => $text, 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false],
        ]);
        $this->driver->runQuery(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT lt_fk_author FOREIGN KEY (%s) REFERENCES %s (%s)',
            $this->driver->quoteIdentifier('lt_books'),
            $this->driver->quoteIdentifier('author_id'),
            $this->driver->quoteIdentifier('lt_authors'),
            $this->driver->quoteIdentifier('id')
        ));
        $this->driver->createIndex('lt_books', 'lt_idx_title', ['title'], false);
        $this->driver->createIndex('lt_authors', 'lt_idx_email', ['email'], true);

        $this->driver->insertRow('lt_authors', ['name' => 'Ada', 'email' => 'ada@example.com']);
        $this->driver->insertRow('lt_authors', ['name' => 'Linus', 'email' => 'linus@example.com']);
        $this->driver->insertRow('lt_books', ['author_id' => 1, 'title' => 'Engines', 'price' => 10.5, 'notes' => null]);
        $this->driver->insertRow('lt_books', ['author_id' => 1, 'title' => 'Notes', 'price' => 20, 'notes' => 'x']);
        $this->driver->insertRow('lt_books', ['author_id' => 2, 'title' => 'Kernels', 'price' => 30, 'notes' => null]);
    }

    protected function tearDown(): void
    {
        if (isset($this->driver)) {
            $this->dropAll();
        }
    }

    private function dropAll(): void
    {
        foreach ($this->driver->listViews() as $view) {
            if (str_starts_with($view, 'lt_')) {
                $this->driver->dropView($view);
            }
        }

        foreach (['lt_books', 'lt_volumes', 'lt_authors', 'lt_tags'] as $table) {
            if (in_array($table, $this->driver->listTables(), true)) {
                $this->driver->dropTable($table);
            }
        }
    }

    public function testDatabasesTablesAndViews(): void
    {
        self::assertTrue($this->driver->supportsDatabases());
        self::assertNotEmpty($this->driver->listDatabases());

        $this->driver->createView('lt_cheap', 'SELECT * FROM ' . $this->driver->quoteIdentifier('lt_books') . ' WHERE price < 25');

        self::assertContains('lt_books', $this->driver->listTables());
        self::assertContains('lt_authors', $this->driver->listTables());
        self::assertContains('lt_cheap', $this->driver->listViews());
        self::assertNotContains('lt_cheap', $this->driver->listTables());

        self::assertSame(2, $this->driver->countRows('lt_cheap'));
        self::assertStringContainsStringIgnoringCase('lt_cheap', $this->driver->getCreateStatement('lt_cheap', true));
    }

    public function testDescribeTable(): void
    {
        $columns = array_column($this->driver->describeTable('lt_books'), null, 'name');

        self::assertSame(['id', 'author_id', 'title', 'price', 'notes'], array_keys($columns));
        self::assertSame('PRI', $columns['id']['key']);
        self::assertTrue($columns['id']['autoIncrement']);
        self::assertFalse($columns['id']['nullable']);
        self::assertFalse($columns['title']['nullable']);
        self::assertTrue($columns['notes']['nullable']);
        self::assertSame('MUL', $columns['title']['key']);

        if ($this->driverKind() === 'mysql') {
            self::assertSame('MUL', $columns['author_id']['key'], 'MySQL indexes foreign key columns automatically');
        }

        $authors = array_column($this->driver->describeTable('lt_authors'), null, 'name');
        self::assertSame('UNI', $authors['email']['key']);
        self::assertFalse($authors['name']['autoIncrement']);
    }

    public function testIndexesAndForeignKeys(): void
    {
        $indexes = array_column($this->driver->listIndexes('lt_books'), null, 'name');

        self::assertArrayHasKey('lt_idx_title', $indexes);
        self::assertSame(['title'], $indexes['lt_idx_title']['columns']);
        self::assertFalse($indexes['lt_idx_title']['unique']);
        self::assertCount(1, array_filter($indexes, static fn (array $i): bool => $i['primary']));

        $authorIndexes = array_column($this->driver->listIndexes('lt_authors'), null, 'name');
        self::assertTrue($authorIndexes['lt_idx_email']['unique']);

        $fks = $this->driver->listForeignKeys('lt_books');
        self::assertCount(1, $fks);
        self::assertSame('lt_fk_author', $fks[0]['name']);
        self::assertSame('author_id', $fks[0]['column']);
        self::assertSame('lt_authors', $fks[0]['refTable']);
        self::assertSame('id', $fks[0]['refColumn']);
        self::assertSame([], $this->driver->listForeignKeys('lt_authors'));
    }

    public function testSearchFiltersAndSorting(): void
    {
        self::assertSame(1, $this->driver->countRows('lt_books', 'kern'));
        self::assertSame(1, $this->driver->countRows('lt_books', 'KERN'), 'search is case-insensitive');
        self::assertSame(2, $this->driver->countRows('lt_books', null, ['author_id' => '=1']));
        self::assertSame(2, $this->driver->countRows('lt_books', null, ['price' => '>= 20']));
        self::assertSame(1, $this->driver->countRows('lt_books', null, ['price' => '< 20']));
        self::assertSame(2, $this->driver->countRows('lt_books', null, ['notes' => 'null']));
        self::assertSame(1, $this->driver->countRows('lt_books', null, ['notes' => '!null']));
        self::assertSame(1, $this->driver->countRows('lt_books', null, ['title' => 'eng']));
        self::assertSame(1, $this->driver->countRows('lt_books', 'e', ['author_id' => '=2']));

        $rows = $this->driver->fetchRows('lt_books', 10, 0, null, 'price', 'desc', ['author_id' => '=1']);
        self::assertSame(['Notes', 'Engines'], array_column($rows, 'title'));

        $page = $this->driver->fetchRows('lt_books', 2, 1, null, 'id', 'asc');
        self::assertSame(['Notes', 'Kernels'], array_column($page, 'title'));
    }

    public function testCrud(): void
    {
        $row = $this->driver->findRow('lt_books', 'id', 1);
        self::assertSame('Engines', $row['title']);
        self::assertNull($row['notes']);

        $this->driver->updateRow('lt_books', 'id', 1, ['title' => 'Engines 2', 'price' => 11, 'notes' => 'edited']);
        $updated = $this->driver->findRow('lt_books', 'id', 1);
        self::assertSame('Engines 2', $updated['title']);
        self::assertSame('edited', $updated['notes']);

        $this->driver->deleteRow('lt_books', 'id', 1);
        self::assertNull($this->driver->findRow('lt_books', 'id', 1));
        self::assertSame(2, $this->driver->countRows('lt_books'));

        $this->driver->createTable('lt_tags', [
            ['name' => 'id', 'type' => $this->types()[0], 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true],
            ['name' => 'label', 'type' => $this->types()[1], 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false],
        ]);
        $this->driver->insertRow('lt_tags', []);
        $this->driver->insertRow('lt_tags', []);
        self::assertSame([1, 2], array_map('intval', array_column($this->driver->fetchRows('lt_tags', 10, 0, null, 'id'), 'id')), 'insert with only defaults');
    }

    public function testSchemaChanges(): void
    {
        [, $text] = $this->types();

        $this->driver->addColumn('lt_books', ['name' => 'isbn', 'type' => $text, 'nullable' => true, 'default' => null]);
        self::assertContains('isbn', array_column($this->driver->describeTable('lt_books'), 'name'));

        $this->driver->addColumn('lt_books', ['name' => 'stock', 'type' => $this->types()[0], 'nullable' => false, 'default' => '0']);
        $stock = array_column($this->driver->describeTable('lt_books'), null, 'name')['stock'];
        self::assertFalse($stock['nullable']);
        self::assertSame('0', (string) $stock['default']);

        $this->driver->renameColumn('lt_books', 'isbn', 'code');
        self::assertContains('code', array_column($this->driver->describeTable('lt_books'), 'name'));

        $this->driver->dropColumn('lt_books', 'code');
        self::assertNotContains('code', array_column($this->driver->describeTable('lt_books'), 'name'));

        $this->driver->createIndex('lt_books', 'lt_idx_price', ['price', 'title'], false);
        $index = array_column($this->driver->listIndexes('lt_books'), null, 'name')['lt_idx_price'];
        self::assertSame(['price', 'title'], $index['columns']);
        $this->driver->dropIndex('lt_books', 'lt_idx_price');
        self::assertArrayNotHasKey('lt_idx_price', array_column($this->driver->listIndexes('lt_books'), null, 'name'));

        $this->driver->renameTable('lt_books', 'lt_volumes');
        self::assertContains('lt_volumes', $this->driver->listTables());
        self::assertNotContains('lt_books', $this->driver->listTables());

        $this->driver->truncateTable('lt_volumes');
        self::assertSame(0, $this->driver->countRows('lt_volumes'));

        $this->driver->dropTable('lt_volumes');
        self::assertNotContains('lt_volumes', $this->driver->listTables());
    }

    public function testCreateStatementRoundTrips(): void
    {
        $sql = $this->driver->getCreateStatement('lt_books', false);

        self::assertStringContainsStringIgnoringCase('CREATE TABLE', $sql);
        self::assertStringContainsString('lt_books', $sql);
        self::assertStringContainsString('lt_idx_title', $sql);
        self::assertStringContainsStringIgnoringCase('FOREIGN KEY', $sql);

        // Re-create under another name from the exported DDL to prove it is valid SQL.
        $this->driver->dropTable('lt_books');
        $this->driver->beginTransaction();
        foreach (lanter_split_sql($sql) as $statement) {
            $this->driver->runQuery($statement);
        }
        $this->driver->commit();

        self::assertContains('lt_books', $this->driver->listTables());
        self::assertSame('lt_idx_title', array_values(array_filter(
            array_column($this->driver->listIndexes('lt_books'), 'name'),
            static fn (string $n): bool => $n === 'lt_idx_title'
        ))[0]);
    }

    public function testRunQueryAndTransactions(): void
    {
        $update = $this->driver->runQuery('UPDATE ' . $this->driver->quoteIdentifier('lt_books') . ' SET price = 1 WHERE author_id = 1');
        self::assertSame([], $update['columns']);
        self::assertSame(2, $update['affected']);

        $select = $this->driver->runQuery('SELECT title, price FROM ' . $this->driver->quoteIdentifier('lt_books') . ' ORDER BY id');
        self::assertSame(['title', 'price'], $select['columns']);
        self::assertCount(3, $select['rows']);

        $this->driver->beginTransaction();
        $this->driver->insertRow('lt_authors', ['name' => 'Temp']);
        $this->driver->rollBack();
        self::assertSame(2, $this->driver->countRows('lt_authors'));

        $this->driver->beginTransaction();
        $this->driver->insertRow('lt_authors', ['name' => 'Kept']);
        $this->driver->commit();
        self::assertSame(3, $this->driver->countRows('lt_authors'));
    }

    public function testQuoting(): void
    {
        self::assertSame('NULL', $this->driver->quoteValue(null));

        // Each dialect escapes differently; what matters is that the literal round-trips.
        $literal = $this->driver->quoteValue("it's \"quoted\" \\ done");
        $result = $this->driver->runQuery('SELECT ' . $literal . ' AS v');
        self::assertSame("it's \"quoted\" \\ done", $result['rows'][0]['v']);

        $this->driver->runQuery('SELECT 1 AS ' . $this->driver->quoteIdentifier('weird name'));
        self::assertNotSame('weird name', $this->driver->quoteIdentifier('weird name'));
    }
}
