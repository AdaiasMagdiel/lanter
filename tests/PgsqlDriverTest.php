<?php

/**
 * Runs when LANTER_TEST_PGSQL is set, e.g.
 *   LANTER_TEST_PGSQL="host=127.0.0.1;port=5432;database=lanter_test;user=postgres;password=secret"
 */
final class PgsqlDriverTest extends DriverContractTestCase
{
    protected function connectionFromEnv(): ?array
    {
        return lanter_test_connection_from_env('LANTER_TEST_PGSQL', 'pgsql');
    }

    protected function driverKind(): string
    {
        return 'pgsql';
    }

    protected function types(): array
    {
        return ['INTEGER', 'VARCHAR(255)', 'NUMERIC(10,2)'];
    }
}
