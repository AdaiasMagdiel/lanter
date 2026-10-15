<?php

/**
 * Runs when LANTER_TEST_MYSQL is set, e.g.
 *   LANTER_TEST_MYSQL="host=127.0.0.1;port=3306;database=lanter_test;user=root;password=secret"
 */
final class MysqlDriverTest extends DriverContractTestCase
{
    protected function connectionFromEnv(): ?array
    {
        return lanter_test_connection_from_env('LANTER_TEST_MYSQL', 'mysql');
    }

    protected function driverKind(): string
    {
        return 'mysql';
    }

    protected function types(): array
    {
        return ['INT', 'VARCHAR(255)', 'DECIMAL(10,2)'];
    }
}
