<?php

use PHPUnit\Framework\TestCase;

final class ConfigAuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_GET = [];
        unset($GLOBALS['LANTER_CONFIG']);
    }

    public function testDefaultsWithoutExternalConfig(): void
    {
        $config = Lanter_Config::resolve();

        self::assertSame('login', $config->authMode());
        self::assertNull($config->authDelegate());
        self::assertSame([], $config->fixedConnections());
        self::assertSame(25, $config->perPage());
        self::assertSame(20, $config->sqlHistorySize());
    }

    public function testGlobalVariableFallbackAndValidation(): void
    {
        $GLOBALS['LANTER_CONFIG'] = ['auth' => ['mode' => 'none'], 'per_page' => 33, 'sql_history' => -1];
        $config = Lanter_Config::resolve();

        self::assertSame('none', $config->authMode());
        self::assertSame(25, $config->perPage(), 'unsupported page sizes fall back to the default');
        self::assertSame(0, $config->sqlHistorySize());
    }

    public function testNoneModeUsesFirstConnectionAndHonoursConnParam(): void
    {
        $GLOBALS['LANTER_CONFIG'] = [
            'auth' => ['mode' => 'none'],
            'connections' => [
                'a' => ['driver' => 'sqlite', 'path' => 'a.db'],
                'b' => ['driver' => 'sqlite', 'path' => 'b.db'],
            ],
        ];
        $auth = new Lanter_Auth(Lanter_Config::resolve());

        self::assertSame('a.db', $auth->resolveConnection()['path']);

        $_GET['conn'] = 'b';
        self::assertSame('b.db', $auth->resolveConnection()['path']);

        $_GET = [];
        self::assertSame('b.db', $auth->resolveConnection()['path'], 'the choice sticks in the session');
        self::assertFalse($auth->deniesWhenUnresolved());
    }

    public function testNoneModeWithoutConnectionsFallsBackToLogin(): void
    {
        $GLOBALS['LANTER_CONFIG'] = ['auth' => ['mode' => 'none']];
        $auth = new Lanter_Auth(Lanter_Config::resolve());

        self::assertNull($auth->resolveConnection());
    }

    public function testDelegateMode(): void
    {
        $allowed = false;
        $GLOBALS['LANTER_CONFIG'] = [
            'auth' => [
                'mode' => 'delegate',
                'delegate' => static function () use (&$allowed): ?array {
                    return $allowed ? ['driver' => 'sqlite', 'path' => 'd.db'] : null;
                },
            ],
        ];
        $auth = new Lanter_Auth(Lanter_Config::resolve());

        self::assertNull($auth->resolveConnection());
        self::assertTrue($auth->deniesWhenUnresolved());

        $allowed = true;
        self::assertSame('d.db', $auth->resolveConnection()['path']);

        // login() in delegate mode only remembers the chosen database, never credentials.
        $auth->login(['driver' => 'pgsql', 'database' => 'app', 'password' => 'secret']);
        self::assertArrayNotHasKey('lanter_connection', $_SESSION);
        self::assertSame('app', $_SESSION['lanter_delegate_database']);
    }

    public function testLoginModeStoresAndClearsSession(): void
    {
        $auth = new Lanter_Auth(Lanter_Config::resolve());

        self::assertNull($auth->resolveConnection());

        $auth->login(['driver' => 'sqlite', 'path' => 'x.db']);
        self::assertSame('x.db', $auth->resolveConnection()['path']);

        $auth->logout();
        self::assertNull($auth->resolveConnection());
    }
}
