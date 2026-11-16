<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Installer;

use Closure;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Database\DatabaseHealthException;
use Nolviko\Core\Installer\DatabaseSetup;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M01.14: database settings are validated and tested before config/local.php is written atomically.
 */
final class DatabaseSetupTest extends TestCase
{
    private const PASSWORD = 'pa55-<never>-echoed';

    private TemporaryDirectory $site;

    /** @var list<Config> */
    private array $verified = [];

    protected function setUp(): void
    {
        $this->site = new TemporaryDirectory();
        mkdir($this->site->path . '/config');
        mkdir($this->site->path . '/public');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testVerifiedSettingsAreWrittenPrivatelyWithANewSecret(): void
    {
        $diagnostics = $this->subject()->configure(self::input());
        $file = $this->site->path . '/config/local.php';

        self::assertSame([], $diagnostics);
        self::assertCount(1, $this->verified);
        self::assertSame('db.example', $this->verified[0]->databaseHost);

        $config = ConfigLoader::load($file, []);

        self::assertNotNull($config);
        self::assertSame(['mysql', 'db.example', 3307, 'forum', 'forum_user'], [
            $config->databaseDriver,
            $config->databaseHost,
            $config->databasePort,
            $config->databaseName,
            $config->databaseUser,
        ]);
        self::assertSame(self::PASSWORD, $config->databasePassword->reveal());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $config->appSecret->reveal());
        self::assertSame([], glob($this->site->path . '/config/.local-*') ?: [], 'no temporary file left');

        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($file) & 0777);
        }
    }

    public function testTheInstallerAddressBecomesTheSiteUrlForMailLinks(): void
    {
        $this->subject()->configure(self::input(), 'https://forum.example.com/community/');
        $kept = ConfigLoader::load($this->site->path . '/config/local.php', []);
        $this->subject()->configure(self::input(), 'https://user@evil.example');
        $refused = ConfigLoader::load($this->site->path . '/config/local.php', []);

        self::assertSame('https://forum.example.com/community', $kept?->appUrl);
        self::assertNull($refused?->appUrl, 'an address that is not a plain base URL is not stored');
    }

    public function testAMariaDbServerIsDetectedAndWrittenAsMariaDb(): void
    {
        $tried = [];
        $setup = new DatabaseSetup($this->site->path . '/config', $this->site->path . '/public', static function (
            Config $config,
        ) use (&$tried): void {
            $tried[] = $config->databaseDriver;

            if ($config->databaseDriver === 'mysql') {
                throw new DatabaseHealthException(['database_family_invalid:mariadb']);
            }
        });

        self::assertSame([], $setup->configure(self::input()));
        self::assertSame(['mysql', 'mariadb'], $tried);
        self::assertSame('mariadb', ConfigLoader::load($this->site->path . '/config/local.php', [])?->databaseDriver);

        $old = new DatabaseSetup($this->site->path . '/config', $this->site->path . '/public', static function (
            Config $config,
        ): void {
            throw new DatabaseHealthException(['database_version_unsupported:10.6.19']);
        });
        self::assertSame(['database_version_unsupported:10.6.19'], $old->configure(self::input()));
    }

    public function testEachInstallGetsItsOwnSecretAndEmptyPortMeansDefault(): void
    {
        $this->subject()->configure(['port' => ''] + self::input());
        $first = ConfigLoader::load($this->site->path . '/config/local.php', []);
        $this->subject()->configure(['port' => ''] + self::input());
        $second = ConfigLoader::load($this->site->path . '/config/local.php', []);

        self::assertNull($first?->databasePort);
        self::assertNotSame($first?->appSecret->reveal(), $second?->appSecret->reveal());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function invalidInputs(): array
    {
        return [
            'missing fields' => [['host' => ' ', 'name' => '', 'user' => ''], [
                'installer_field_missing:host',
                'installer_field_missing:name',
                'installer_field_missing:user',
            ]],
            'bad port' => [['port' => '70000'], ['installer_field_invalid:port']],
            'non-numeric port' => [['port' => '33o6'], ['installer_field_invalid:port']],
            'bad database name' => [['name' => 'forum; DROP'], ['installer_field_invalid:name']],
            'control character' => [['host' => "db\nexample"], ['installer_field_invalid:host']],
            'array instead of text' => [['user' => ['x']], ['installer_field_missing:user']],
            'overlong password' => [['password' => str_repeat('p', 4097)], ['installer_field_invalid:password']],
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @param list<string> $expected
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRefusedBeforeConnecting(array $override, array $expected): void
    {
        self::assertSame($expected, $this->subject()->configure($override + self::input()));
        self::assertSame([], $this->verified);
        self::assertFileDoesNotExist($this->site->path . '/config/local.php');
    }

    public function testFailedConnectionWritesNothingAndNeverEchoesThePassword(): void
    {
        $failing = static function (): void {
            throw new DatabaseHealthException(['database_auth_failed']);
        };
        $setup = new DatabaseSetup($this->site->path . '/config', $this->site->path . '/public', $failing);

        $diagnostics = $setup->configure(self::input());

        self::assertSame(['database_auth_failed'], $diagnostics);
        self::assertStringNotContainsString(self::PASSWORD, implode(' ', $diagnostics));
        self::assertFileDoesNotExist($this->site->path . '/config/local.php');
    }

    public function testConfigInsideThePublicDirectoryIsRefused(): void
    {
        mkdir($this->site->path . '/public/config');
        $public = $this->site->path . '/public';
        $setup = new DatabaseSetup($public . '/config', $public, $this->verifier());

        self::assertSame(['config_directory_public'], $setup->configure(self::input()));
        self::assertFileDoesNotExist($this->site->path . '/public/config/local.php');
    }

    public function testUnwritableConfigDirectoryIsReported(): void
    {
        $setup = new DatabaseSetup($this->site->path . '/missing', $this->site->path . '/public', $this->verifier());

        self::assertSame(['config_write_failed'], $setup->configure(self::input()));
    }

    private function subject(): DatabaseSetup
    {
        return new DatabaseSetup($this->site->path . '/config', $this->site->path . '/public', $this->verifier());
    }

    private function verifier(): Closure
    {
        return function (Config $config): void {
            $this->verified[] = $config;
        };
    }

    /**
     * @return array<string, string>
     */
    private static function input(): array
    {
        return [
            'host' => 'db.example',
            'port' => '3307',
            'name' => 'forum',
            'user' => 'forum_user',
            'password' => self::PASSWORD,
        ];
    }
}
