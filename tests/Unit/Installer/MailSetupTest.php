<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Installer;

use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Installer\MailSetup;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Mail setup preserves database identity/secret and refuses invalid settings or an installed site. */
final class MailSetupTest extends TestCase
{
    private TemporaryDirectory $scratch;

    private MailSetup $setup;

    protected function setUp(): void
    {
        $this->scratch = new TemporaryDirectory();
        $this->scratch->write('public/.keep', '');
        $this->scratch->write('config/local.php', '<?php return ' . var_export([
            'database' => ['driver' => 'mysql', 'host' => 'localhost', 'name' => 'fixture', 'user' => 'fixture',
                'password' => 'fixture-db-secret'],
            'app' => ['secret' => str_repeat('a', 64)],
        ], true) . ';');
        $this->setup = new MailSetup($this->scratch->path . '/config', $this->scratch->path . '/public');
    }

    protected function tearDown(): void
    {
        $this->scratch->remove();
    }

    public function testSmtpSavePreservesDatabaseAndApplicationSecret(): void
    {
        $this->setup->save(['transport' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => '587',
            'smtp_encryption' => 'starttls', 'smtp_user' => 'fixture', 'smtp_password' => 'fixture-smtp-secret',
            'from_address' => 'forum@example.com']);
        $settings = require $this->scratch->path . '/config/local.php';

        self::assertSame('fixture-db-secret', $settings['database']['password']);
        self::assertSame(str_repeat('a', 64), $settings['app']['secret']);
        self::assertSame(587, $settings['mail']['smtp_port']);
        self::assertSame('fixture-smtp-secret', $settings['mail']['smtp_password']);
    }

    public function testInvalidPortDoesNotOverwriteConfiguration(): void
    {
        $before = file_get_contents($this->scratch->path . '/config/local.php');

        try {
            $this->setup->save(['transport' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 'broken',
                'from_address' => 'forum@example.com']);
            self::fail('invalid port was accepted');
        } catch (ConfigException) {
            self::assertSame($before, file_get_contents($this->scratch->path . '/config/local.php'));
        }
    }

    public function testInstalledSiteCannotReconfigureMailThroughInstaller(): void
    {
        $this->scratch->write('config/install.lock', 'installed');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('installer_closed');
        $this->setup->save(['transport' => 'mail']);
    }
}
