<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Logging;

use LogicException;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Logging\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class RedactorTest extends TestCase
{
    private const R = Redactor::REDACTED;

    public function testSensitiveKeysAreRedactedAtAnyDepth(): void
    {
        $context = [
            'password' => 'p1',
            'Password' => 'p2',
            'db_password' => 'p3',
            'passwd' => 'p4',
            'api_token' => 't1',
            'csrfToken' => 't2',
            'app-secret' => 's1',
            'Authorization' => 'Bearer x',
            'cookie' => 'sid=1',
            'Set-Cookie' => 'sid=2',
            'session_id' => 'abc',
            'apiKey' => 'k',
            'private_key' => 'k2',
            'user' => ['name' => 'caner', 'credentials' => ['pin' => 1], 'nested' => ['deeper' => ['pwd' => 'x']]],
            'passport' => 'not-a-secret',
            'count' => 3,
        ];

        self::assertSame([
            'password' => self::R,
            'Password' => self::R,
            'db_password' => self::R,
            'passwd' => self::R,
            'api_token' => self::R,
            'csrfToken' => self::R,
            'app-secret' => self::R,
            'Authorization' => self::R,
            'cookie' => self::R,
            'Set-Cookie' => self::R,
            'session_id' => self::R,
            'apiKey' => self::R,
            'private_key' => self::R,
            'user' => ['name' => 'caner', 'credentials' => self::R, 'nested' => ['deeper' => ['pwd' => self::R]]],
            'passport' => 'not-a-secret',
            'count' => 3,
        ], (new Redactor())->context($context));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function texts(): array
    {
        return [
            ['mysql://forum:hunter2@db:3306/forum', 'mysql://forum:' . self::R . '@db:3306/forum'],
            ['connect pgsql://u:p%40ss@h/db failed', 'connect pgsql://u:' . self::R . '@h/db failed'],
            ['pgsql:host=db;dbname=f;user=u;password=hunter2;port=5432', 'pgsql:host=db;dbname=f;user=u;password='
                . self::R . ';port=5432'],
            ['login failed: password=hunter2 for caner', 'login failed: password=' . self::R . ' for caner'],
            ['db_password: "two words" end', 'db_password: ' . self::R . ' end'],
            ['?a=1&reset_token=abc123&b=2', '?a=1&reset_token=' . self::R . '&b=2'],
            ["Authorization: Bearer eyJ.abc\nnext", 'Authorization: ' . self::R . "\nnext"],
            ['Cookie: sid=1; theme=dark', 'Cookie: ' . self::R],
            ['api_key=k-123, more', 'api_key=' . self::R . ', more'],
            ['no secrets in here', 'no secrets in here'],
            ['the password field was empty', 'the password field was empty'],
        ];
    }

    #[DataProvider('texts')]
    public function testSecretsInFreeTextAreRedacted(string $text, string $expected): void
    {
        self::assertSame($expected, (new Redactor())->text($text));
    }

    public function testKnownSecretValuesAreScrubbedEverywhere(): void
    {
        $redactor = new Redactor([new Secret('Kn0wn-Pa55'), 'app-secret-value-123', 'short']);

        self::assertSame(
            'auth failed for ' . self::R . ' and ' . self::R . ' but short stays',
            $redactor->text('auth failed for Kn0wn-Pa55 and app-secret-value-123 but short stays'),
        );
        self::assertSame(['note' => 'x ' . self::R], $redactor->context(['note' => 'x Kn0wn-Pa55']));
    }

    public function testExceptionsAreNormalizedWithRedactedMessagesAndNoArguments(): void
    {
        $redactor = new Redactor(['Kn0wn-Pa55']);
        $previous = new LogicException('dsn mysql://u:hunter2@h/db');
        $thrower = static fn(string $password): never => throw new RuntimeException(
            'SQLSTATE[28000] password=' . $password,
            7,
            $previous,
        );

        try {
            $thrower('Kn0wn-Pa55');
        } catch (RuntimeException $error) {
            $data = $redactor->context(['exception' => $error])['exception'];
        }

        $json = (string) json_encode($data);
        self::assertSame(RuntimeException::class, $data['class']);
        self::assertSame(7, $data['code']);
        self::assertSame('SQLSTATE[28000] password=' . self::R, $data['message']);
        self::assertSame('dsn mysql://u:' . self::R . '@h/db', $data['previous']['message']);
        self::assertNotEmpty($data['trace']);
        self::assertStringNotContainsString('Kn0wn-Pa55', $json, 'trace frames carry no arguments');
        self::assertStringNotContainsString('hunter2', $json);
    }

    public function testObjectsAreNeverDumpedAndDepthIsBounded(): void
    {
        $object = new stdClass();
        $object->password = 'hunter2';
        $deep = ['x'];

        for ($i = 0; $i < Redactor::MAX_DEPTH + 2; ++$i) {
            $deep = ['level' => $deep];
        }

        $context = ['object' => $object, 'secret_object' => new Secret('s'), 'deep' => $deep];
        $redacted = (new Redactor())->context($context);

        self::assertSame(stdClass::class, $redacted['object']);
        self::assertSame(self::R, $redacted['secret_object']);
        self::assertStringContainsString('[depth]', (string) json_encode($redacted['deep']));
    }
}
