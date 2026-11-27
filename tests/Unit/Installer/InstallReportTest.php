<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Installer;

use Nolviko\Core\Installer\InstallReport;
use Nolviko\Core\Runtime\DirectoryMove;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 2026-11-27, roadmap RC1 acceptance: one report with only the domain and version, never a second one, and an
 * unreachable receiver never fails the installation.
 */
final class InstallReportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nolviko-report-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0700, true);
    }

    protected function tearDown(): void
    {
        DirectoryMove::removeTree($this->root);
    }

    public function testSendsTheDomainAndVersionOnce(): void
    {
        $this->release('0.1.0-rc.1');
        $sent = [];
        $record = static function (string $url, string $body) use (&$sent): void {
            $sent[] = [$url, $body];
        };
        $report = new InstallReport($this->root, $this->root . '/config', $record);

        self::assertTrue($report->send('Forum.Example.org:8443'));
        self::assertFalse($report->send('forum.example.org'), 'a double click or a repeated finish sends nothing');
        self::assertSame([[InstallReport::ENDPOINT, '{"domain":"forum.example.org","version":"0.1.0-rc.1"}']], $sent);
    }

    public function testAFailingReceiverIsIgnored(): void
    {
        $this->release('0.1.0-rc.1');
        $report = new InstallReport($this->root, $this->root . '/config', static function (): void {
            throw new RuntimeException('unreachable');
        });

        self::assertTrue($report->send('forum.example.org'));
    }

    public function testADevelopmentCheckoutSendsNothing(): void
    {
        $sent = 0;
        $report = new InstallReport($this->root, $this->root . '/config', static function () use (&$sent): void {
            $sent++;
        });

        self::assertNull($report->version());
        self::assertFalse($report->send('forum.example.org'));
        self::assertSame(0, $sent);
        self::assertFileDoesNotExist($this->root . '/config/' . InstallReport::MARKER);
    }

    public function testDomainNormalisation(): void
    {
        self::assertSame('forum.example.org', InstallReport::domain(' FORUM.example.org. '));
        self::assertSame('localhost', InstallReport::domain('localhost:8080'));
        self::assertNull(InstallReport::domain(''));
        self::assertNull(InstallReport::domain('bad host/'));
    }

    private function release(string $version): void
    {
        file_put_contents($this->root . '/config/release-compatibility.json', json_encode(['version' => $version]));
    }
}
