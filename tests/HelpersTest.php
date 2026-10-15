<?php

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_SERVER['HTTP_X_LANTER_TOKEN'] = '';
    }

    public function testSplitSqlHonoursQuotesAndComments(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('x;y'); -- comment; still comment\nSELECT 1; /* block; */ SELECT \"q;r\";";

        self::assertSame([
            "INSERT INTO t (a) VALUES ('x;y')",
            'SELECT 1',
            'SELECT "q;r"',
        ], lanter_split_sql($sql));
    }

    public function testSplitSqlIgnoresTrailingWhitespace(): void
    {
        self::assertSame(['SELECT 1'], lanter_split_sql("  SELECT 1 ;  \n"));
        self::assertSame([], lanter_split_sql("  ;; \n"));
    }

    public function testReadOnlyStatementDetection(): void
    {
        self::assertTrue(lanter_sql_is_read_only('SELECT * FROM t'));
        self::assertTrue(lanter_sql_is_read_only("  with x as (select 1) select * from x"));
        self::assertTrue(lanter_sql_is_read_only('EXPLAIN SELECT 1'));
        self::assertTrue(lanter_sql_is_read_only('PRAGMA table_info(t)'));
        self::assertFalse(lanter_sql_is_read_only('DELETE FROM t'));
        self::assertFalse(lanter_sql_is_read_only('SELECT 1; DROP TABLE t'));
        self::assertFalse(lanter_sql_is_read_only('INSERT INTO t SELECT * FROM u'));
    }

    public function testCsrfTokenIsStableAndValidated(): void
    {
        $token = lanter_csrf_token();

        self::assertSame($token, lanter_csrf_token());
        self::assertStringContainsString($token, lanter_csrf_field());
        self::assertFalse(lanter_csrf_is_valid());

        $_POST['_token'] = $token;
        self::assertTrue(lanter_csrf_is_valid());

        $_POST['_token'] = 'nope';
        self::assertFalse(lanter_csrf_is_valid());
    }

    public function testIdentifiers(): void
    {
        self::assertTrue(lanter_is_valid_identifier('users_2'));
        self::assertTrue(lanter_is_valid_identifier('_x'));
        self::assertFalse(lanter_is_valid_identifier('2x'));
        self::assertFalse(lanter_is_valid_identifier('a-b'));
        self::assertFalse(lanter_is_valid_identifier(''));
    }

    public function testReadOnlyHelpers(): void
    {
        self::assertFalse(lanter_is_read_only(['driver' => 'sqlite']));
        self::assertTrue(lanter_is_read_only(['driver' => 'sqlite', 'read_only' => true]));

        $this->expectException(RuntimeException::class);
        lanter_assert_writable(['read_only' => true]);
    }

    public function testDriverKindAndLabel(): void
    {
        self::assertSame('mysql', lanter_driver_kind(['driver' => 'mariadb']));
        self::assertSame('pgsql', lanter_driver_kind(['driver' => 'postgres']));
        self::assertSame('sqlite', lanter_driver_kind([]));
        self::assertSame('PostgreSQL', lanter_driver_label(['driver' => 'pgsql']));
        self::assertSame('MariaDB', lanter_driver_label(['driver' => 'mariadb']));
    }

    public function testColumnInputSpec(): void
    {
        self::assertSame('checkbox', lanter_column_input_spec('tinyint(1)')['kind']);
        self::assertSame('checkbox', lanter_column_input_spec('BOOLEAN')['kind']);
        self::assertSame('number', lanter_column_input_spec('bigint')['kind']);
        self::assertSame('any', lanter_column_input_spec('decimal(10,2)')['step']);
        self::assertSame('datetime-local', lanter_column_input_spec('timestamp')['kind']);
        self::assertSame('textarea', lanter_column_input_spec('jsonb')['kind']);
        self::assertSame('textarea', lanter_column_input_spec('longtext')['kind']);
        self::assertSame(['a', "b'c"], lanter_column_input_spec("enum('a','b\\'c')")['options']);
    }

    public function testFormatCount(): void
    {
        self::assertSame('999', lanter_format_count(999));
        self::assertSame('1.5k', lanter_format_count(1500));
        self::assertSame('2M', lanter_format_count(2_000_000));
    }
}
