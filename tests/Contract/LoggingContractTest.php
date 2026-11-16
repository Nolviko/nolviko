<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Http\FrontController;
use Nolviko\Core\Logging\LoggerReporter;
use Nolviko\Tests\Support\SiteFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M01.06 end to end: an unexpected failure is logged as redacted JSON under `<storage>/logs/`, and a log that
 * cannot be written never changes the response.
 */
final class LoggingContractTest extends TestCase
{
    public function testUnhandledFailureIsLoggedWithIncidentAndWithoutSecrets(): void
    {
        $site = SiteFixture::installed();
        mkdir($site->storagePath());
        $failure = new RuntimeException('connect failed with ' . SiteFixture::DB_PASSWORD . ' and '
            . SiteFixture::APP_SECRET . ' via mysql://forum:other-pass@db/forum');

        try {
            $response = $site->respond(
                static fn(): Request => throw $failure,
                reporter: null,
                environment: [FrontController::STORAGE_ENVIRONMENT => $site->storagePath()],
            );
            $log = implode('', array_map('file_get_contents', glob($site->storagePath() . '/logs/*.log') ?: []));
        } finally {
            $site->remove();
        }

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        preg_match('/[0-9a-f]{16}/', (string) $response->getContent(), $incident);
        $record = json_decode(trim($log), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(LoggerReporter::MESSAGE, $record['message']);
        self::assertSame($incident[0], $record['context']['incident']);
        self::assertSame(RuntimeException::class, $record['context']['exception']['class']);

        foreach ([SiteFixture::DB_PASSWORD, SiteFixture::APP_SECRET, 'other-pass'] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
    }

    public function testUnwritableLogDirectoryDoesNotChangeTheResponse(): void
    {
        $site = SiteFixture::installed();
        $blockedStorage = $site->storagePath();
        file_put_contents($blockedStorage, 'a file where storage/ should be');
        $previous = ini_set('error_log', '/dev/null');

        try {
            $ok = $site->respond(
                Request::create('/robots.txt'),
                null,
                [FrontController::STORAGE_ENVIRONMENT => $blockedStorage],
            );
            $failure = $site->respond(
                static fn(): Request => throw new RuntimeException('boom'),
                null,
                [FrontController::STORAGE_ENVIRONMENT => $blockedStorage],
            );
        } finally {
            ini_set('error_log', (string) $previous);
            $site->remove();
        }

        self::assertSame(Response::HTTP_OK, $ok->getStatusCode());
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $failure->getStatusCode());
    }

    public function testPsrLogStaysLockedAndInstalled(): void
    {
        $lock = json_decode((string) file_get_contents(SiteFixture::root() . '/composer.lock'), true);
        $names = array_column($lock['packages'], 'name');

        self::assertContains('psr/log', $names, 'psr/log is used directly; declare it if a dependency drops it');
        self::assertFileExists(SiteFixture::root() . '/vendor/psr/log/src/LoggerInterface.php');
    }
}
