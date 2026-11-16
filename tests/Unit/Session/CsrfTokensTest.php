<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Session;

use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Tests\Support\ArraySession;
use PHPUnit\Framework\TestCase;

final class CsrfTokensTest extends TestCase
{
    public function testTokensAreMaskedPerRenderAndAllValid(): void
    {
        $tokens = new CsrfTokens(new ArraySession());
        $first = $tokens->token();
        $second = $tokens->token();

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{86}$/D', $first);
        self::assertTrue($tokens->isValid($first));
        self::assertTrue($tokens->isValid($second));
    }

    public function testValidationNeverCreatesASession(): void
    {
        $session = new ArraySession();
        $tokens = new CsrfTokens($session);

        self::assertFalse($tokens->isValid(str_repeat('A', 86)));
        self::assertFalse($tokens->isValid(null));
        self::assertFalse($session->isStarted());
        self::assertSame([], $session->data);
    }

    public function testForeignTamperedAndMalformedTokensAreRefused(): void
    {
        $tokens = new CsrfTokens(new ArraySession());
        $valid = $tokens->token();
        $foreign = (new CsrfTokens(new ArraySession()))->token();
        $tampered = substr($valid, 0, -2) . ($valid[-2] === 'A' ? 'B' : 'A') . $valid[-1];

        foreach (['', $foreign, $tampered, substr($valid, 0, -4), $valid . 'AAAA', '!' . substr($valid, 1)] as $token) {
            self::assertFalse($tokens->isValid($token), $token);
        }
    }

    public function testRotateAndInvalidateRevokeEarlierTokens(): void
    {
        $session = new ArraySession();
        $tokens = new CsrfTokens($session);
        $beforeLogin = $tokens->token();

        $tokens->rotate();
        self::assertFalse($tokens->isValid($beforeLogin));

        $afterLogin = $tokens->token();
        $session->invalidate();
        self::assertFalse($tokens->isValid($afterLogin));
    }

    public function testCorruptStoredSecretIsReplaced(): void
    {
        $session = new ArraySession();
        $session->set('_csrf_secret', ['not', 'a', 'secret']);
        $tokens = new CsrfTokens($session);

        self::assertTrue($tokens->isValid($tokens->token()));
        self::assertIsString($session->data['_csrf_secret']);
    }
}
