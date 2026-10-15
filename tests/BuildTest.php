<?php

use PHPUnit\Framework\TestCase;

final class BuildTest extends TestCase
{
    public function testDistIsInSyncWithSources(): void
    {
        $root = dirname(__DIR__);
        $current = file_get_contents($root . '/dist/lanter.php');

        $tmp = tempnam(sys_get_temp_dir(), 'lanter');
        exec(sprintf('php %s %s 2>&1', escapeshellarg($root . '/build.php'), escapeshellarg($tmp)), $output, $exit);

        self::assertSame(0, $exit, implode("\n", $output));
        self::assertSame($current, file_get_contents($tmp), 'dist/lanter.php is stale: run `php build.php`');
        unlink($tmp);
    }

    public function testDistHasNoSyntaxErrorsAndIsGuarded(): void
    {
        $dist = dirname(__DIR__) . '/dist/lanter.php';
        exec(sprintf('php -l %s 2>&1', escapeshellarg($dist)), $output, $exit);

        self::assertSame(0, $exit, implode("\n", $output));
        self::assertStringContainsString("if(!function_exists('lanter_run'))", file_get_contents($dist));
    }
}
