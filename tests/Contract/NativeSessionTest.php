<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Session\NativeSession;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * M01.11 with PHP's real session module, one fresh process per simulated request (session-worker.php).
 */
final class NativeSessionTest extends TestCase
{
    private TemporaryDirectory $storage;

    protected function setUp(): void
    {
        $this->storage = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->storage->remove();
    }

    public function testSettingsAreAppliedFromCodeAndDataStaysInPrivateStorage(): void
    {
        $result = $this->request([['set', 'user', 7]], url: 'https://forum.example/');
        $directory = $this->storage->path . '/' . NativeSession::DIRECTORY;
        $expected = [
            'session.save_handler' => 'files',
            'session.save_path' => $directory,
            'session.name' => NativeSession::COOKIE,
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
            'session.cookie_httponly' => '1',
            'session.cookie_samesite' => 'lax',
            'session.cookie_secure' => '1',
            'session.gc_probability' => '1',
            'session.gc_maxlifetime' => (string) NativeSession::LIFETIME,
        ];

        self::assertSame($expected, array_intersect_key($result['ini'], $expected));
        self::assertNotSame(sys_get_temp_dir(), $result['ini']['session.save_path']);
        self::assertSame([], $result['errors'], 'no warning or deprecation');
        self::assertFileExists($this->sessionFile((string) $result['id']));

        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0700, fileperms($directory) & 0777);
        }
    }

    public function testDeprecatedIdSettingsKeepPhpDefaults(): void
    {
        $result = $this->request([['set', 'k', 1]]);
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Core/Session/NativeSession.php');

        // The forum leaves these deprecated settings alone: whatever this PHP's php.ini says (32/4 built in, 26/5 in
        // php.ini-production, as on GitHub's runners) is what the session uses (beta.10 CI matrix).
        self::assertSame([ini_get('session.sid_length'), ini_get('session.sid_bits_per_character')], [
            $result['ini']['session.sid_length'],
            $result['ini']['session.sid_bits_per_character'],
        ]);
        self::assertSame(0, preg_match('/session\.sid_(length|bits_per_character)\'/', $source));
    }

    public function testCookieIsIssuedByTheResponseWithSecureAttributes(): void
    {
        $secure = $this->request([['set', 'k', 1]], url: 'https://forum.example/');
        $plain = $this->request([['set', 'k', 1]]);
        $subdirectory = $this->request([['set', 'k', 1]], url: 'http://forum.example/forum/', server: [
            'SCRIPT_NAME' => '/forum/index.php',
            'SCRIPT_FILENAME' => '/var/www/forum/index.php',
        ]);

        self::assertCount(1, $secure['cookies']);
        self::assertStringStartsWith(NativeSession::COOKIE . '=' . $secure['id'] . ';', $secure['cookies'][0]);
        self::assertStringContainsString('; path=/; secure; httponly; samesite=lax', $secure['cookies'][0]);
        self::assertStringNotContainsString('expires=', $secure['cookies'][0], 'a browser-session cookie');
        self::assertStringNotContainsString('secure', $plain['cookies'][0]);
        self::assertStringContainsString('; path=/forum;', $subdirectory['cookies'][0]);
        self::assertStringContainsString('private', (string) $secure['cache_control']);
        self::assertStringContainsString('no-cache', (string) $secure['cache_control']);
    }

    public function testReadingWithoutCookieStartsNothing(): void
    {
        $result = $this->request([['get', 'user'], ['remove', 'user']]);

        self::assertSame(['default'], $result['results']);
        self::assertFalse($result['started']);
        self::assertSame([], $result['cookies']);
        self::assertDirectoryDoesNotExist($this->storage->path . '/' . NativeSession::DIRECTORY);
    }

    public function testCookieResumesTheSessionWithoutReissuingIt(): void
    {
        $first = $this->request([['set', 'user', 7]]);
        $second = $this->request([['get', 'user']], $first['id']);

        self::assertSame([7], $second['results']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame([], $second['cookies']);
    }

    public function testStrictModeReplacesAnIdPhpDidNotCreate(): void
    {
        $fixed = str_repeat('a', 32);
        $written = $this->request([['set', 'user', 7]], $fixed);
        $readOnly = $this->request([['get', 'user']], str_repeat('b', 32));

        self::assertNotSame($fixed, $written['id']);
        self::assertStringContainsString(NativeSession::COOKIE . '=' . $written['id'], $written['cookies'][0]);
        self::assertFileDoesNotExist($this->sessionFile($fixed));
        self::assertSame(['default'], $readOnly['results']);
        self::assertStringContainsString('=deleted;', $readOnly['cookies'][0], 'a stale cookie is cleared');
        self::assertCount(1, $this->sessionFiles(), 'no empty session was kept for the stale cookie');
    }

    public function testRegenerateKeepsDataUnderANewIdAndDeletesTheOldFile(): void
    {
        $login = $this->request([['set', 'user', 7]]);
        $privilege = $this->request([['regenerate'], ['get', 'user']], $login['id']);
        $old = $this->request([['get', 'user']], $login['id']);

        self::assertNotSame($login['id'], $privilege['id']);
        self::assertSame([7], $privilege['results']);
        self::assertStringContainsString('=' . $privilege['id'] . ';', $privilege['cookies'][0]);
        self::assertFileDoesNotExist($this->sessionFile((string) $login['id']));
        self::assertSame(['default'], $old['results'], 'the old ID no longer resumes anything');
    }

    public function testInvalidateDropsDataAndId(): void
    {
        $login = $this->request([['set', 'user', 7], ['set', '_csrf_secret', 'x']]);
        $logout = $this->request([['invalidate'], ['get', 'user'], ['get', '_csrf_secret']], $login['id']);

        self::assertNotSame($login['id'], $logout['id']);
        self::assertSame(['default', 'default'], $logout['results']);
        self::assertFileDoesNotExist($this->sessionFile((string) $login['id']));
    }

    public function testMalformedCookieIsIgnored(): void
    {
        $result = $this->request([['get', 'user']], '../../etc/passwd');

        self::assertFalse($result['started']);
        self::assertSame([], $result['errors']);
    }

    /**
     * @param list<list<string|int>> $actions
     * @param array<string, string> $server
     *
     * @return array{ini: array<string, string|false>, results: list<mixed>, started: bool, id: string|null,
     *     cookies: list<string>, cache_control: string|null, errors: list<string>}
     */
    private function request(
        array $actions,
        ?string $cookie = null,
        string $url = 'http://forum.example/',
        array $server = [],
    ): array {
        $scenario = json_encode(['url' => $url, 'cookie' => $cookie, 'server' => $server, 'actions' => $actions]);
        $command = [PHP_BINARY, __DIR__ . '/session-worker.php', $this->storage->path, (string) $scenario];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    private function sessionFile(string $id): string
    {
        return $this->storage->path . '/' . NativeSession::DIRECTORY . '/sess_' . $id;
    }

    /**
     * @return list<string>
     */
    private function sessionFiles(): array
    {
        return glob($this->storage->path . '/' . NativeSession::DIRECTORY . '/sess_*') ?: [];
    }
}
