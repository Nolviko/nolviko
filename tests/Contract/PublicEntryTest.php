<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * public/index.php end to end through a real SAPI (PHP built-in server on loopback), plus the web-root rule.
 */
final class PublicEntryTest extends TestCase
{
    private const STARTUP_ATTEMPTS = 50;

    private const STARTUP_DELAY_MICROSECONDS = 100_000;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    public function testIndexIsTheOnlyPhpFileInPublic(): void
    {
        $phpFiles = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::root() . '/public', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (preg_match('/\.(php\d?|phtml|phar)$/i', $file->getFilename()) === 1) {
                $phpFiles[] = substr($file->getPathname(), strlen(self::root() . '/public/'));
            }
        }

        self::assertSame(['index.php'], $phpFiles);
    }

    public function testRealRequestsThroughIndexPhp(): void
    {
        $this->startServer();

        // A fresh copy has no config/local.php: the site sends visitors to the installer.
        [$status, $headers] = $this->request('GET', '/');
        self::assertSame(302, $status);
        self::assertContains('Location: /install', $headers);

        [$status, , $body] = $this->request('GET', '/install');
        self::assertSame(200, $status);
        self::assertStringStartsWith('<!doctype html>', $body);

        [$status, , $body] = $this->request('GET', '/missing?x=%3Cscript%3E');
        self::assertSame(404, $status);
        self::assertStringNotContainsString('<script>', $body);

        [$status, $headers] = $this->request('POST', '/', ['X-HTTP-Method-Override: GET']);
        self::assertSame(405, $status);
        self::assertContains('Allow: GET, HEAD', $headers);

        [$status] = $this->request('GET', '/x/%2e%2e/');
        self::assertSame(400, $status);
    }

    private function startServer(): void
    {
        $this->port = self::freePort();
        $this->server = proc_open(
            [
                PHP_BINARY,
                '-d',
                'memory_limit=128M',
                '-S',
                '127.0.0.1:' . $this->port,
                '-t',
                self::root() . '/public',
                self::root() . '/public/index.php',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            self::root(),
            ['DB_DRIVER' => 'mysql', 'FORUM_STORAGE_DIR' => sys_get_temp_dir() . '/nolviko-no-storage'],
        );

        for ($attempt = 0; $attempt < self::STARTUP_ATTEMPTS; ++$attempt) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(self::STARTUP_DELAY_MICROSECONDS);
        }

        self::fail('built-in server did not start on port ' . $this->port);
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: list<string>, 2: string}
     */
    private function request(string $method, string $path, array $headers = []): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ]]);
        $body = file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);
        $responseHeaders = http_get_last_response_headers() ?? [];

        if ($body === false || $responseHeaders === []) {
            throw new RuntimeException('request_failed:' . $method . ' ' . $path);
        }

        return [(int) explode(' ', $responseHeaders[0])[1], $responseHeaders, $body];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('free_port_unavailable');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function root(): string
    {
        return (string) realpath(dirname(__DIR__, 2));
    }
}
