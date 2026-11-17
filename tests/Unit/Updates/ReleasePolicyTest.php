<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Database\Migration\ExpandOnlyPolicy;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Updates\ReleaseCompatibility;
use Nolviko\Core\Updates\ReleaseEligibility;
use Nolviko\Core\Updates\ReleaseVersion;
use Nolviko\Core\Updates\SiteAccessLock;
use Nolviko\Core\Updates\UpdateTrust;
use Nolviko\Modules\Importers\Infrastructure\ImportTables;
use Nolviko\Tests\Support\FileSwitchFixture;
use Nolviko\Tests\Support\FixtureMigration;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReleasePolicyTest extends TestCase
{
    public static function metadata(string $version = '1.2.0'): string
    {
        return json_encode(['format' => 1, 'version' => $version, 'extension_api' => '1.0.0', 'theme_api' => 1,
            'php' => '>=8.4.27 <8.5 || >=8.5.12 <8.6', 'databases' => ['mysql' => '>=8.4 <9'],
            'extensions' => ['intl'], 'packages' => ['twig/twig' => '3.30.0']], JSON_THROW_ON_ERROR);
    }

    public static function platform(): array
    {
        return ['integrity' => true, 'writable' => true, 'backup' => true, 'quiescent' => true,
            'schema' => true, 'private_storage' => true, 'php' => '8.4.27', 'driver' => 'mysql',
            'database_version' => '8.4.7', 'extensions' => ['intl'], 'free_bytes' => 100000000,
            'required_bytes' => 64000000];
    }

    public function testImporterSchemaUsesTheConservativeExpandUpdateSubset(): void
    {
        foreach (['mysql', 'mariadb'] as $backend) {
            ExpandOnlyPolicy::assertAllowed(new ImportTables(), $backend);
            self::assertNotEmpty((new ImportTables())->statements('mysql'));
        }
    }

    public function testForeignKeyAcceptanceNeverPermitsDataDeletionOrChangingExistingConstraints(): void
    {
        $statements = [
            'DELETE FROM users',
            'CREATE TABLE sample (id BIGINT); DELETE FROM users',
            'ALTER TABLE sample ADD CONSTRAINT fk FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE',
            "CREATE TABLE sample (note VARCHAR(100) DEFAULT 'ON DELETE CASCADE')",
            'CREATE TABLE sample (id BIGINT, FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE) DROP TABLE users',
        ];
        foreach ($statements as $sql) {
            try {
                ExpandOnlyPolicy::assertAllowed(new FixtureMigration('9999_guard', [$sql]), 'mysql');
                self::fail('destructive/unsupported statement accepted');
            } catch (MigrationException $failure) {
                self::assertStringStartsWith('update_migration_', $failure->diagnostic);
            }
        }
    }

    public function testStableSemverDoesNotUseLexicalOrdering(): void
    {
        self::assertTrue((new ReleaseVersion('v1.10.0'))->newerThan(new ReleaseVersion('1.9.9')));
        self::assertFalse((new ReleaseVersion('1.2.0-rc.1'))->newerThan(new ReleaseVersion('1.2.0')));
        self::assertSame('stable', (new ReleaseVersion('1.2.0+build.2'))->channel());
        self::assertSame('prerelease', (new ReleaseVersion('1.2.0-rc.1'))->channel());
    }

    #[DataProvider('badVersions')]
    public function testBadVersionsFail(string $version): void
    {
        $this->expectException(RuntimeException::class);
        new ReleaseVersion($version);
    }

    public static function badVersions(): array
    {
        return [['1.2'], ['01.2.3'], ['1.2.3-01'], ['1.2.3 '], ['dev-main'], ['../../1.2.3']];
    }

    public function testEachEligibilityGateFailsClosed(): void
    {
        $target = ReleaseCompatibility::parse(self::metadata());
        $policy = new ReleaseEligibility();
        $current = new ReleaseVersion('1.1.0');
        self::assertSame([], $policy->assess($target, $current, 'stable', self::platform(), [], true));
        foreach (['integrity', 'writable', 'backup', 'quiescent', 'schema', 'private_storage'] as $gate) {
            $platform = self::platform();
            unset($platform[$gate]);
            self::assertContains(
                'update_' . $gate . '_required',
                $policy->assess($target, $current, 'stable', $platform, [], true)
            );
        }
        self::assertContains(
            'update_confirmation_required',
            $policy->assess($target, $current, 'stable', self::platform(), [], false)
        );
        self::assertContains(
            'update_not_newer',
            $policy->assess($target, new ReleaseVersion('2.0.0'), 'stable', self::platform(), [], true)
        );
        self::assertContains('update_addon_package_incompatible', $policy->assess(
            $target,
            $current,
            'stable',
            self::platform(),
            [['engine' => '^1.0', 'php' => '^8.4', 'packages' => ['twig/twig' => '^4']]],
            true
        ));
        self::assertContains('update_channel_mismatch', $policy->assess(
            ReleaseCompatibility::parse(self::metadata('1.2.0-rc.1')),
            $current,
            'stable',
            self::platform(),
            [],
            true
        ));
    }

    public function testCompatibilityMetadataIsBoundToExactSignedArchiveBytes(): void
    {
        $fixture = new FileSwitchFixture(new: ['config/release-compatibility.json' => self::metadata()]);
        try {
            self::assertSame('1.2.0', ReleaseCompatibility::fromArchive(
                $fixture->archive,
                $fixture->manifest
            )->version->value);
            file_put_contents($fixture->archive, 'tamper', FILE_APPEND);
            $this->expectException(RuntimeException::class);
            ReleaseCompatibility::fromArchive($fixture->archive, $fixture->manifest);
        } finally {
            $fixture->root->remove();
        }
    }

    public function testMissingTrustIsNotifyOnlyAndMalformedFieldsAreRejected(): void
    {
        $root = new TemporaryDirectory();
        try {
            self::assertNull(UpdateTrust::load($root->path));
            $root->write('config/update-trust.json', '{"format":1,"keys":{}}');
            $this->expectException(RuntimeException::class);
            UpdateTrust::load($root->path);
        } finally {
            $root->remove();
        }
    }

    public function testOfficialReleaseTrustAppliesUnlessTheSiteHasItsOwn(): void
    {
        $root = new TemporaryDirectory();
        $trust = static fn(string $owner, string $key): string => json_encode(['format' => 1, 'owner' => $owner,
            'repository' => 'nolviko', 'installed_version' => '0.1.0-beta.4', 'channel' => 'prerelease',
            'keys' => ['nolviko-release-1' => base64_encode(str_repeat($key, 32))]], JSON_THROW_ON_ERROR);
        try {
            // ADR-037: the release ships config/release-trust.json, so a fresh install trusts official updates.
            $root->write('config/release-trust.json', $trust('Nolviko', 'o'));
            self::assertSame('Nolviko', UpdateTrust::load($root->path)?->repository->owner);

            // The site's own config/update-trust.json (never touched by updates) wins.
            $root->write('config/update-trust.json', $trust('example', 's'));
            self::assertSame('example', UpdateTrust::load($root->path)?->repository->owner);
        } finally {
            $root->remove();
        }
    }

    public function testExclusiveCaptureCannotOverlapRequests(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);
        $lock = new SiteAccessLock($root->path);
        $reader = $lock->acquire(false);
        try {
            self::assertNotFalse($reader);
            self::assertFalse($lock->acquire(true));
            SiteAccessLock::release($reader);
            $reader = false;
            $writer = $lock->acquire(true);
            self::assertNotFalse($writer);
            try {
                self::assertFalse($lock->acquire(false));
            } finally {
                SiteAccessLock::release($writer);
            }
        } finally {
            if ($reader !== false) {
                SiteAccessLock::release($reader);
            }
            $root->remove();
        }
    }
}
