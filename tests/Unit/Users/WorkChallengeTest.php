<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Users;

use Nolviko\Modules\Users\Http\QuestionChallenge;
use Nolviko\Modules\Users\Http\WorkChallenge;
use Nolviko\Tests\Support\ArraySession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-035: proof of work is checked once against the session salt; the sum question is the no-JS fallback. */
final class WorkChallengeTest extends TestCase
{
    public function testAValidNoncePassesOnceAndAWrongOneFails(): void
    {
        $session = new ArraySession();
        $challenge = $this->challenge($session);
        $presented = $challenge->present();

        self::assertSame('fixed-salt', $presented['work']['salt'] ?? null);
        self::assertSame(WorkChallenge::DIFFICULTY, $presented['work']['bits'] ?? null);
        self::assertSame('auth.challenge.sum', $presented['id'] ?? null, 'the fallback question is always shown');

        $nonce = self::solve('fixed-salt');
        self::assertTrue($challenge->passes([WorkChallenge::FIELD => $nonce]));
        self::assertFalse($challenge->passes([WorkChallenge::FIELD => $nonce]), 'a salt is used only once');

        $challenge->present();
        self::assertFalse($challenge->passes([WorkChallenge::FIELD => (string) ((int) $nonce + 1)]));
        $challenge->present();
        self::assertFalse($challenge->passes([WorkChallenge::FIELD => 'abc']));
    }

    public function testWithoutWorkTheSumMustBeAnswered(): void
    {
        $session = new ArraySession();
        $challenge = $this->challenge($session);

        $challenge->present();
        self::assertTrue($challenge->passes([QuestionChallenge::FIELD => '7']), '3 + 4');
        $challenge->present();
        self::assertFalse($challenge->passes([QuestionChallenge::FIELD => '8']));
        $challenge->present();
        self::assertFalse($challenge->passes([]), 'nothing answered');

        $challenge->present();
        self::assertTrue($challenge->passes([WorkChallenge::FIELD => self::solve('fixed-salt')]));
        self::assertFalse(
            $challenge->passes([QuestionChallenge::FIELD => '7']),
            'solving the work also used up the sum',
        );
    }

    /** @return list<array{string, int}> */
    public static function digests(): array
    {
        return [["\x00\x00\x01", 23], ["\x80", 0], ["\x01\xff", 7], ["\x00\x08", 12], ["\x00\x00", 16]];
    }

    #[DataProvider('digests')]
    public function testLeadingZeroBits(string $digest, int $bits): void
    {
        self::assertSame($bits, WorkChallenge::leadingZeroBits($digest));
    }

    private function challenge(ArraySession $session): WorkChallenge
    {
        $numbers = [3, 4];
        $next = 0;

        return new WorkChallenge(
            $session,
            new QuestionChallenge($session, static function () use (&$numbers, &$next): int {
                return $numbers[$next++ % 2];
            }),
            static fn(): string => 'fixed-salt',
        );
    }

    private static function solve(string $salt): string
    {
        for ($nonce = 0;; $nonce++) {
            if (WorkChallenge::leadingZeroBits(hash('sha256', $salt . $nonce, true)) >= WorkChallenge::DIFFICULTY) {
                return (string) $nonce;
            }
        }
    }
}
