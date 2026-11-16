<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Users;

use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Modules\Users\Domain\RememberTokens;
use Nolviko\Modules\Users\Domain\SessionEpochs;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Tests\Support\ArraySession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** A rotated remember token must not create even one authenticated request for an inactive/deleting account. */
final class InactiveRememberTest extends TestCase
{
    public function testInactiveRememberedUserIsRejectedAndReplacementRevoked(): void
    {
        $session = new ArraySession();
        $remember = $this->createMock(RememberTokens::class);
        $remember->expects(self::once())->method('consume')->with('old')->willReturn([7, 'rotated']);
        $remember->expects(self::once())->method('revoke')->with('rotated');
        $epochs = $this->createStub(SessionEpochs::class);
        $epochs->method('current')->willReturn(null);
        $auth = new AuthSession($session, new CsrfTokens($session), $remember, new ResponseCookies(), $epochs);
        $request = Request::create('https://forum.example.com/', cookies: [AuthSession::REMEMBER_COOKIE => 'old']);

        self::assertNull($auth->userId($request));
        self::assertSame([], $session->data);
        self::assertSame(0, $session->regenerations);
    }
}
