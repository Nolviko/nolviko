<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Tests\Support\ArraySession;
use PHPUnit\Framework\TestCase;

/**
 * M02.15, owner decisions 2026-11-17/18: ordinary admin work is always allowed (isFresh), while the popup confirmation
 * for actions that can break the site stays for FRESH_SECONDS without use and slides while used (isConfirmed).
 */
final class AdminSessionTest extends TestCase
{
    public function testFreshnessWindow(): void
    {
        $session = new ArraySession();
        $now = new DateTimeImmutable('2026-11-01 12:00:00', new DateTimeZone('UTC'));
        $at = static fn(int $seconds): AdminSession => new AdminSession(
            $session,
            static fn(): DateTimeImmutable => $now->modify('+' . $seconds . ' seconds'),
        );

        self::assertTrue($at(0)->isFresh(), 'ordinary admin work never asks for the password');
        self::assertFalse($at(0)->isConfirmed(), 'never confirmed');

        $at(0)->confirm();

        self::assertTrue($at(0)->isConfirmed());
        self::assertTrue($at(AdminSession::FRESH_SECONDS - 1)->isConfirmed(), 'active just before the idle limit');
        self::assertTrue($at(2 * AdminSession::FRESH_SECONDS - 2)->isConfirmed(), 'that activity slid the window');
        self::assertFalse($at(3 * AdminSession::FRESH_SECONDS - 1)->isConfirmed(), 'idle for a full window');
        self::assertSame(0, $at(3 * AdminSession::FRESH_SECONDS - 1)->remainingSeconds());
    }

    public function testATamperedValueIsNotFresh(): void
    {
        $session = new ArraySession();
        $session->set('_admin_reauth_at', '9999999999');

        self::assertFalse((new AdminSession($session))->isConfirmed(), 'only an integer written by confirm() counts');
    }
}
