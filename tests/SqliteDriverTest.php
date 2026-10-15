<?php

use PHPUnit\Framework\TestCase;

final class SqliteDriverTest extends TestCase
{
    private Lanter_SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new Lanter_SqliteDriver();
        $this->driver->connect(['driver' => 'sqlite', 'path' => ':memory:']);
        $this->driver->runQuery('CREATE TABLE authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT UNIQUE)');
        $this->driver->runQuery('CREATE TABLE books (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER REFERENCES authors(id), title TEXT NOT NULL, price REAL, notes TEXT)');
        $this->driver->runQuery('CREATE INDEX idx_books_title ON books (title)');
        $this->driver->insertRow('authors', ['name' => 'Ada', 'email' => 'ada@example.com']);
        $this->driver->insertRow('authors', ['name' => 'Linus', 'email' => 'linus@example.com']);
        $this->driver->insertRow('books', ['author_id' => 1, 'title' => 'Engines', 'price' => 10.5, 'notes' => null]);
        $this->driver->insertRow('books', ['author_id' => 1, 'title' => 'Notes', 'price' => 20, 'notes' => 'x']);
        $this->driver->insertRow('books', ['author_id' => 2, 'title' => 'Kernels', 'price' => 30, 'notes' => null]);
    }

    public function testListsTablesAndViews(): void
    {
        $this->driver->createView('cheap_books', 'SELECT * FROM books WHERE price < 25');

        self::assertSame(['authors', 'books'], $this->driver->listTables());
        self::assertSame(['cheap_books'], $this->driver->listViews());
    }

    public function testDescribeTableMarksKeys(): void
    {
        $columns = array_column($this->driver->describeTable('authors'), null, 'name');

        self::assertSame('PRI', $columns['id']['key']);
        self::assertTrue($columns['id']['autoIncrement']);
        self::assertSame('UNI', $columns['email']['key']);
        self::assertFalse($columns['name']['nullable']);
        self::assertSame('MUL', array_column($this->driver->describeTable('books'), null, 'name')['title']['key']);
    }

    public function testListsIndexesAndForeignKeys(): void
    {
        $indexes = array_column($this->driver->listIndexes('books'), null, 'name');
        self::assertSame(['title'], $indexes['idx_books_title']['columns']);
        self::assertFalse($indexes['idx_books_title']['unique']);

        $fks = $this->driver->listForeignKeys('books');
        self::assertCount(1, $fks);
        self::assertSame('author_id', $fks[0]['column']);
        self::assertSame('authors', $fks[0]['refTable']);
        self::assertSame('id', $fks[0]['refColumn']);
    }

    public function testSearchAndFilters(): void
    {
        self::assertSame(1, $this->driver->countRows('books', 'kern'));
        self::assertSame(2, $this->driver->countRows('books', null, ['author_id' => '=1']));
        self::assertSame(2, $this->driver->countRows('books', null, ['price' => '>= 20']));
        self::assertSame(2, $this->driver->countRows('books', null, ['notes' => 'null']));
        self::assertSame(1, $this->driver->countRows('books', null, ['notes' => '!null']));
        self::assertSame(1, $this->driver->countRows('books', null, ['title' => 'eng']));
        self::assertSame(0, $this->driver->countRows('books', null, ['unknown_column' => 'ignored']) - 3);

        $rows = $this->driver->fetchRows('books', 10, 0, null, 'price', 'desc', ['author_id' => '=1']);
        self::assertSame(['Notes', 'Engines'], array_column($rows, 'title'));
    }

    public function testCrud(): void
    {
        $this->driver->updateRow('books', 'id', 1, ['title' => 'Engines 2', 'price' => 11]);
        self::assertSame('Engines 2', $this->driver->findRow('books', 'id', 1)['title']);

        $this->driver->deleteRow('books', 'id', 1);
        self::assertNull($this->driver->findRow('books', 'id', 1));
        self::assertSame(2, $this->driver->countRows('books'));
    }

    public function testSchemaChanges(): void
    {
        $this->driver->addColumn('books', ['name' => 'isbn', 'type' => 'TEXT', 'nullable' => true, 'default' => null]);
        self::assertContains('isbn', array_column($this->driver->describeTable('books'), 'name'));

        $this->driver->renameColumn('books', 'isbn', 'code');
        self::assertContains('code', array_column($this->driver->describeTable('books'), 'name'));

        $this->driver->dropColumn('books', 'code');
        self::assertNotContains('code', array_column($this->driver->describeTable('books'), 'name'));

        $this->driver->createIndex('books', 'idx_books_price', ['price'], true);
        self::assertTrue(array_column($this->driver->listIndexes('books'), null, 'name')['idx_books_price']['unique']);
        $this->driver->dropIndex('books', 'idx_books_price');
        self::assertArrayNotHasKey('idx_books_price', array_column($this->driver->listIndexes('books'), null, 'name'));

        $this->driver->renameTable('books', 'volumes');
        self::assertSame(['authors', 'volumes'], $this->driver->listTables());

        $this->driver->truncateTable('volumes');
        self::assertSame(0, $this->driver->countRows('volumes'));

        $this->driver->dropTable('volumes');
        self::assertSame(['authors'], $this->driver->listTables());
    }

    public function testAddNotNullColumnWithoutDefaultIsRejectedOnPopulatedTable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->driver->addColumn('books', ['name' => 'x', 'type' => 'TEXT', 'nullable' => false, 'default' => null]);
    }

    public function testCreateTableWithAutoIncrement(): void
    {
        $this->driver->createTable('tags', [
            ['name' => 'id', 'type' => 'INTEGER', 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true],
            ['name' => 'label', 'type' => 'TEXT', 'nullable' => false, 'primaryKey' => false, 'autoIncrement' => false],
        ]);

        $this->driver->insertRow('tags', ['label' => 'a']);
        $this->driver->insertRow('tags', ['label' => 'b']);

        self::assertSame(2, $this->driver->countRows('tags'));
        self::assertStringContainsString('AUTOINCREMENT', $this->driver->getCreateStatement('tags', false));
    }

    public function testCreateStatementIncludesIndexes(): void
    {
        $sql = $this->driver->getCreateStatement('books', false);

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('CREATE INDEX idx_books_title', $sql);
    }

    public function testRunQueryReportsAffectedRows(): void
    {
        $result = $this->driver->runQuery("UPDATE books SET price = 1 WHERE author_id = 1");

        self::assertSame([], $result['columns']);
        self::assertSame(2, $result['affected']);

        $select = $this->driver->runQuery('SELECT title, price FROM books ORDER BY id');
        self::assertSame(['title', 'price'], $select['columns']);
        self::assertCount(3, $select['rows']);
    }

    public function testTransactionsRollBack(): void
    {
        $this->driver->beginTransaction();
        $this->driver->insertRow('authors', ['name' => 'Temp', 'email' => 't@example.com']);
        $this->driver->rollBack();

        self::assertSame(2, $this->driver->countRows('authors'));
    }
}
