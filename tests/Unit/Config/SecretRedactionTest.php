<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Config;

use LogicException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Logging\JsonFileLogger;
use Nolviko\Tests\Support\SiteFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * M01.05 redaction contract: secrets never appear in dumps, logs or serialized forms of the config.
 */
final class SecretRedactionTest extends TestCase
{
    public function testEveryDumpOfTheConfigIsRedacted(): void
    {
        $config = self::config();
        $dumps = [
            'var_dump' => self::capture(static fn() => var_dump($config)),
            'print_r' => print_r($config, true),
            'var_export' => var_export($config, true),
            'json_encode' => (string) json_encode($config),
            'json_encode redacted' => (string) json_encode($config->redacted()),
            // debug_zval_dump ignores __debugInfo: proves the value is not stored as a property at all.
            'debug_zval_dump' => self::capture(static fn() => debug_zval_dump($config)),
            'string cast' => (string) $config->databasePassword . (string) $config->appSecret,
            'log line' => self::logLine([
                'config' => $config,
                'exception' => new RuntimeException(print_r($config, true)),
                'password' => $config->databasePassword,
            ]),
        ];

        foreach ($dumps as $name => $dump) {
            self::assertStringNotContainsString(SiteFixture::DB_PASSWORD, $dump, $name);
            self::assertStringNotContainsString(SiteFixture::APP_SECRET, $dump, $name);
        }

        self::assertStringContainsString(Secret::REDACTED, $dumps['print_r']);
        self::assertSame('forum', $config->redacted()['database']['user'], 'non-secrets stay readable');
    }

    public function testSecretsRefuseSerialization(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('secret_not_serializable');

        serialize(self::config());
    }

    public function testExceptionTraceArgumentsDoNotRevealSecrets(): void
    {
        $secret = new Secret(SiteFixture::DB_PASSWORD);
        $thrower = static fn(Secret $value): never => throw new RuntimeException('failed');

        try {
            $thrower($secret);
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString(SiteFixture::DB_PASSWORD, $exception->getTraceAsString());
            self::assertStringNotContainsString(SiteFixture::DB_PASSWORD, print_r($exception->getTrace(), true));
        }

        self::assertSame(SiteFixture::DB_PASSWORD, $secret->reveal());
    }

    private static function config(): Config
    {
        $site = SiteFixture::notConfigured();

        try {
            return ConfigLoader::load($site->writeConfig(SiteFixture::validConfig()), []) ?? self::fail('no config');
        } finally {
            $site->remove();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function logLine(array $context): string
    {
        $directory = new TemporaryDirectory();

        try {
            (new JsonFileLogger($directory->path))->error('redaction_probe', $context);

            return implode('', array_map('file_get_contents', glob($directory->path . '/*.log') ?: []));
        } finally {
            $directory->remove();
        }
    }

    private static function capture(callable $print): string
    {
        ob_start();
        $print();

        return (string) ob_get_clean();
    }
}
