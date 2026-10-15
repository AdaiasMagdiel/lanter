<?php

use PHPUnit\Framework\TestCase;

final class RedirectTest extends TestCase
{
    public function testRedirectHaltsAndExposesTarget(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/db?action=row_new&table=t';
        lanter_redirect_target(null, true);

        try {
            @lanter_redirect('dashboard', ['table' => 'users']);
            self::fail('Expected Lanter_Halt');
        } catch (Lanter_Halt) {
            // expected: the request ends here instead of exit()
        }

        self::assertSame('/admin/db?action=dashboard&table=users', lanter_redirect_target());
    }
}
