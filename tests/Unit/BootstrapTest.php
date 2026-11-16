<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit;

use InvalidArgumentException;
use Nolviko\Core\Runtime\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

final class BootstrapTest extends TestCase
{
    private const TODAY = '2026-10-31';

    public function testInitializesRealProject(): void
    {
        self::assertSame(realpath(self::root()), Bootstrap::boot(self::root())->projectDirectory);
    }

    /**
     * config/bootstrap.php loads app/vendor itself, so it runs in a fresh process (as on a host).
     */
    public function testEntryBootstrapWorksWithTheAppVendorAlone(): void
    {
        $probe = 'echo (require $argv[1]) instanceof ' . Bootstrap::class . ' ? "ok" : "wrong";';
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', '-r', $probe, self::root() . '/config/bootstrap.php'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), (string) $errors);
        self::assertSame('ok', $output);
    }

    public function testMissingRootFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Bootstrap::boot(self::root() . '/missing');
    }

    public function testUnsupportedDriverFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database_driver_unsupported');

        Bootstrap::boot(self::root(), 'unsupported');
    }

    public function testHeadersCannotOverrideMethodOrClient(): void
    {
        Bootstrap::boot(self::root());

        self::assertSame([], Request::getAllowedHttpMethodOverride());

        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $request = Request::create(
                '/',
                'POST',
                ['_method' => $method],
                server: [
                    'HTTP_X_HTTP_METHOD_OVERRIDE' => $method,
                    'REMOTE_ADDR' => '127.0.0.1',
                    'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
                ],
            );

            self::assertSame('POST', $request->getMethod());
            self::assertSame('127.0.0.1', $request->getClientIp());
        }
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    public static function versions(): array
    {
        return [
            ['8.4.26', false],
            ['8.4.27', true],
            ['8.5.11', false],
            ['8.5.12', true],
            ['8.6.0', false],
            ['8.5.12RC1', false],
        ];
    }

    #[DataProvider('versions')]
    public function testSecurityFloor(string $version, bool $accepted): void
    {
        $errors = \forumRuntimeErrors($version, self::extensions(), '128M', self::TODAY);

        self::assertSame($accepted, !in_array('php_version_unsupported', $errors, true));
    }

    public function testExpiryDriverAndMemory(): void
    {
        $extensions = self::extensions();

        self::assertContains(
            'php_version_unsupported',
            \forumRuntimeErrors('8.4.27', $extensions, '128M', '2029-01-01'),
        );
        self::assertContains(
            'extension_missing:pdo_pgsql',
            \forumRuntimeErrors('8.4.27', $extensions, '128M', self::TODAY, 'postgresql'),
        );
        self::assertSame(
            [],
            \forumRuntimeErrors('8.4.27', [...$extensions, 'pdo_pgsql'], '128M', self::TODAY, 'postgresql'),
        );
        self::assertContains(
            'memory_limit_below_128M',
            \forumRuntimeErrors('8.4.27', $extensions, '64M', self::TODAY),
        );
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function extensions(): array
    {
        return [
            'PDO',
            'pdo_mysql',
            'intl',
            'mbstring',
            'zip',
            'json',
            'openssl',
            'fileinfo',
            'session',
            'sodium',
            'gd',
        ];
    }
}
