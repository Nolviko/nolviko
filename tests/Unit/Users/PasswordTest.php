<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Users;

use Nolviko\Core\Logging\JsonFileLogger;
use Nolviko\Core\Logging\Redactor;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\PasswordPolicy;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;

/**
 * M02.02: Argon2id/bcrypt hashing without truncation, rehash on login, length and common-password policy,
 * and passwords kept out of logs and traces.
 */
final class PasswordTest extends TestCase
{
    public function testArgon2idIsPreferredWithTheDocumentedParameters(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('correct horse battery staple');
        $info = password_get_info($hash);

        self::assertSame(PASSWORD_ARGON2ID, $hasher->algorithm());
        $options = array_intersect_key($info['options'], PasswordHasher::ARGON2ID_OPTIONS);

        self::assertSame(PasswordHasher::ARGON2ID_OPTIONS, $options);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('correct horse battery stapl', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testBcryptDoesNotTruncateAfter72Bytes(): void
    {
        $bcrypt = new PasswordHasher(argon2: false);
        $long = str_repeat('a', 72);
        $hash = $bcrypt->hash($long . 'X');

        self::assertStringStartsWith('$2y$11$', $hash);
        self::assertTrue($bcrypt->verify($long . 'X', $hash));
        self::assertFalse($bcrypt->verify($long . 'Y', $hash), 'byte 73 matters');
        self::assertFalse($bcrypt->verify($long, $hash));
        self::assertTrue($bcrypt->verify("nul\0inside", $bcrypt->hash("nul\0inside")));
        self::assertFalse($bcrypt->verify('nul', $bcrypt->hash("nul\0inside")), 'NUL does not end the password');
    }

    public function testLoginUpgradesOldHashesOnlyAfterASuccessfulVerify(): void
    {
        $old = (new PasswordHasher(argon2: false))->hash('s3cret-enough');
        $hasher = new PasswordHasher();
        $stored = [];
        $store = static function (string $hash) use (&$stored): void {
            $stored[] = $hash;
        };

        self::assertTrue($hasher->needsRehash($old));
        self::assertFalse($hasher->verifyAndUpgrade('wrong-password', $old, $store));
        self::assertSame([], $stored, 'a failed login never rewrites the hash');
        self::assertTrue($hasher->verifyAndUpgrade('s3cret-enough', $old, $store));
        self::assertCount(1, $stored);
        self::assertTrue($hasher->verify('s3cret-enough', $stored[0]));
        self::assertFalse($hasher->needsRehash($stored[0]));
        self::assertTrue($hasher->verifyAndUpgrade('s3cret-enough', $stored[0], $store));
        self::assertCount(1, $stored, 'a current hash is not rewritten');
    }

    public function testUnknownOrBrokenHashesNeverVerify(): void
    {
        $hasher = new PasswordHasher();

        foreach (['', 'plain-text', md5('x'), '$2y$04$short'] as $hash) {
            self::assertFalse($hasher->verify('x', $hash), $hash);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function policyCases(): array
    {
        return [
            'too short' => ['short12', [], ['password_too_short']],
            'eight emoji are eight characters' => [str_repeat("\u{1F600}", 8), [], []],
            'too long' => [str_repeat('x', PasswordPolicy::MAX_LENGTH + 1), [], ['password_too_long']],
            'maximum is accepted' => [str_repeat('x', PasswordPolicy::MAX_LENGTH - 1) . 'y', [], []],
            'common' => ['Password123', [], ['password_too_common']],
            'common Turkish' => ['Galatasaray', [], ['password_too_common']],
            'same as username' => ['DenizKaya1', ['denizkaya1', 'deniz@example.com'], ['password_matches_account']],
            'same as email local part' => [
                'deniz.kaya99',
                ['dk', 'Deniz.Kaya99@example.com'],
                ['password_matches_account'],
            ],
            'no composition rules' => ['correct horse battery staple', ['deniz'], []],
        ];
    }

    /**
     * @param list<string> $personal
     * @param list<string> $expected
     */
    #[DataProvider('policyCases')]
    public function testPolicy(string $password, array $personal, array $expected): void
    {
        self::assertSame($expected, PasswordPolicy::check($password, $personal));
    }

    public function testCommonListIsBundledAndCovered(): void
    {
        $lines = file(PasswordPolicy::COMMON_LIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $words = array_values(array_filter($lines, static fn(string $line): bool => !str_starts_with($line, '#')));

        self::assertGreaterThan(150, count($words));
        self::assertSame($words, array_values(array_unique($words)), 'no duplicates');

        foreach ($words as $word) {
            if (mb_strlen($word) >= PasswordPolicy::MIN_LENGTH) {
                self::assertSame(['password_too_common'], PasswordPolicy::check(strtoupper($word)), $word);
            }
        }
    }

    public function testPasswordsAreSensitiveParametersAndRedactedInLogs(): void
    {
        foreach (['hash', 'verify', 'verifyAndUpgrade'] as $method) {
            $parameter = (new ReflectionMethod(PasswordHasher::class, $method))->getParameters()[0];
            self::assertNotEmpty($parameter->getAttributes(SensitiveParameter::class), $method);
        }

        $logs = new TemporaryDirectory();
        $logger = new JsonFileLogger($logs->path, new Redactor([]));
        $logger->error('login_failed', [
            'password' => 'Hunter2-Secret',
            'new_password' => 'Hunter3-Secret',
            'exception' => new RuntimeException('verify failed'),
        ]);
        $written = implode('', array_map('file_get_contents', glob($logs->path . '/*.log') ?: []));
        $logs->remove();

        self::assertStringNotContainsString('Hunter2-Secret', $written);
        self::assertStringNotContainsString('Hunter3-Secret', $written);
        self::assertStringContainsString(Redactor::REDACTED, $written);
    }
}
