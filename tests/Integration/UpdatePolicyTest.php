<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\RecoveryDispatcher;
use Nolviko\Core\Database\UpdateCapture;
use Nolviko\Core\Database\UpdateJournal;
use Nolviko\Core\Database\UpdatePreflight;
use Nolviko\Core\Database\UpdatePreparation;
use Nolviko\Core\Http\RecoveryGateway;
use Nolviko\Core\Updates\RecoveryGrantStore;
use Nolviko\Core\Updates\RecoveryJobContext;
use Nolviko\Core\Updates\ReleaseCompatibility;
use Nolviko\Core\Updates\UpdateStage;
use Nolviko\Tests\Support\ReleaseSigningFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use ZipArchive;

final class UpdatePolicyTest extends TestCase
{
    private Connection $db;
    private TemporaryDirectory $files;
    private TemporaryDirectory $private;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }
        $this->db = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32))
        ));
        if (!TestDatabase::isDisposable($this->db)) {
            self::fail('refusing installed forum');
        }
        TestDatabase::reset($this->db);
        $this->files = new TemporaryDirectory();
        $this->private = new TemporaryDirectory();
        $this->private->write('storage/.keep', '');
        $this->files->write('storage/.keep', '');
        // Owner decision 2026-11-17: updates work with storage/ at 755 as well as 700.
        chmod($this->private->path . '/storage', 0755);
        $this->files->write('public/.keep', '');
        $this->files->write('themes/.keep', '');
        $this->files->write('extensions/.keep', '');
        $this->files->write('config/local.php', 'private fixture; never included');
        $this->files->write('config/install.lock', 'installed');
        $lock = $this->files->write('src/Core/Updates/SiteAccessLock.php', 'fixture-lock-baseline');
        $this->files->write('config/runtime-files.json', json_encode(['src/Core/Updates/SiteAccessLock.php'
            => hash_file('sha256', $lock)], JSON_THROW_ON_ERROR));
        $this->files->write('config/update-trust.json', json_encode(['format' => 1, 'owner' => 'example',
            'repository' => 'forum', 'installed_version' => '1.0.0', 'channel' => 'stable',
            'keys' => ['test-key' => base64_encode(ReleaseSigningFixture::key())]], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            TestDatabase::release($this->db);
            $this->files->remove();
            $this->private->remove();
        }
    }

    public function testSignedUploadResumesAfterCheckedCheckpointWithoutExecutingTargetCode(): void
    {
        $metadata = json_encode(['format' => 1, 'version' => '1.1.0', 'extension_api' => '1.0.0',
            'theme_api' => 1, 'php' => '>=8.2.35 <8.6',
            'databases' => ['mysql' => '>=8.0 <10', 'mariadb' => '>=10.6 <13', 'postgresql' => '>=14 <19'],
            'extensions' => ['intl', 'zip'], 'packages' => []], JSON_THROW_ON_ERROR);
        $entries = ['config/release-compatibility.json' => $metadata,
            'src/Core/Updates/SiteAccessLock.php' => '<?php throw new RuntimeException("must_not_execute");'];
        $archive = $this->files->path . '/release.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL));
        foreach ($entries as $path => $bytes) {
            $zip->addFromString($path, $bytes);
            $zip->setCompressionName($path, ZipArchive::CM_STORE);
        }
        $zip->close();
        $body = ReleaseSigningFixture::body(['archive_bytes' => filesize($archive),
            'archive_sha256' => hash_file('sha256', $archive), 'file_count' => count($entries),
            'unpacked_bytes' => array_sum(array_map(strlen(...), $entries)), 'issued_at' => time() - 60,
            'expires_at' => time() + 3600]);
        $service = new UpdatePreparation(
            $this->db,
            $this->files->path,
            $this->private->path . '/storage',
            TestDatabase::driver()
        );
        $job = $service->receive($archive, $body, ReleaseSigningFixture::sign($body), 1, true);
        $journal = new UpdateJournal($this->db);
        $now = new DateTimeImmutable();
        $token = $journal->claim($job, $now);
        $run = $journal->find($job);
        $run = $journal->advance($job, $token, $run->revision, UpdateStage::Checked, $now);
        $journal->release($job, $token, $run->revision, $now);
        $result = $service->step($job, 1);
        self::assertTrue($result['ready']);
        self::assertSame(UpdateStage::Staged, $journal->find($job)->stage);
        self::assertSame('fixture-lock-baseline', file_get_contents(
            $this->files->path . '/src/Core/Updates/SiteAccessLock.php'
        ));
        self::assertSame($metadata, file_get_contents($this->private->path . '/storage/updates/' . $job
            . '/stage/config/release-compatibility.json'));
        mkdir($this->private->path . '/storage/recovery', 0700);
        self::assertTrue($service->publish($job, 1));
        $context = RecoveryJobContext::load($this->private->path . '/storage/recovery', $job);
        self::assertSame('1.1.0', $context->data['release_version']);
        self::assertStringStartsWith($context->data['swap_root'] . '/', $context->data['staging']);
        $token = $journal->claim($job, new DateTimeImmutable());
        $run = (new UpdateCapture(
            $this->db,
            $journal,
            $this->files->path,
            $this->private->path . '/storage'
        ))->run($context, $journal->find($job), $token);
        self::assertSame(UpdateStage::Maintenance, $run->stage);
        self::assertNotNull($journal->backup($job));
        self::assertSame(
            'nolviko-update:' . $job . "\n",
            file_get_contents($this->files->path . '/config/maintenance.lock')
        );
        $journal->release($job, $token, $run->revision, new DateTimeImmutable());
        $dispatcher = new RecoveryDispatcher(
            $this->db,
            $journal,
            $this->files->path,
            $this->private->path . '/storage/recovery',
            TestDatabase::driver(),
            false,
            null,
        );
        $switched = $dispatcher->actions()['resume']($job, 1);
        self::assertSame(UpdateStage::FilesSwitched->value, $switched['stage']);
        self::assertSame($entries['src/Core/Updates/SiteAccessLock.php'], file_get_contents(
            $this->files->path . '/src/Core/Updates/SiteAccessLock.php',
        ));
    }

    /**
     * 2026-11-19: the recovery gateway refused its own configuration (no `backup` among its commands), so every
     * request to update-recovery.php answered 503 and no update could pass the recovery page. Drive the signed update
     * through the gateway, as the browser does, until the files are switched.
     */
    public function testSignedUpdateRunsThroughTheRecoveryGateway(): void
    {
        $metadata = json_encode(['format' => 1, 'version' => '1.1.0', 'extension_api' => '1.0.0',
            'theme_api' => 1, 'php' => '>=8.2.35 <8.6',
            'databases' => ['mysql' => '>=8.0 <10', 'mariadb' => '>=10.6 <13', 'postgresql' => '>=14 <19'],
            'extensions' => ['intl', 'zip'], 'packages' => []], JSON_THROW_ON_ERROR);
        $entries = ['config/release-compatibility.json' => $metadata,
            'src/Core/Updates/SiteAccessLock.php' => '<?php // new release'];
        $archive = $this->files->path . '/release.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL));
        foreach ($entries as $path => $bytes) {
            $zip->addFromString($path, $bytes);
            $zip->setCompressionName($path, ZipArchive::CM_STORE);
        }
        $zip->close();
        $body = ReleaseSigningFixture::body(['archive_bytes' => filesize($archive),
            'archive_sha256' => hash_file('sha256', $archive), 'file_count' => count($entries),
            'unpacked_bytes' => array_sum(array_map(strlen(...), $entries)), 'issued_at' => time() - 60,
            'expires_at' => time() + 3600]);
        $storage = $this->private->path . '/storage';
        $service = new UpdatePreparation($this->db, $this->files->path, $storage, TestDatabase::driver());
        $job = $service->receive($archive, $body, ReleaseSigningFixture::sign($body), 1, true);
        for ($round = 0; !$service->step($job, 1)['ready'] && $round < 20; $round++) {
            // Staging continues in small steps, as the one-click page does.
        }
        mkdir($storage . '/recovery', 0700);
        mkdir($storage . '/recovery/grants', 0700);
        self::assertTrue($service->publish($job, 1));

        $origin = 'https://forum.example.test';
        $grants = new RecoveryGrantStore($storage . '/recovery/grants', $this->files->path . '/public');
        $grant = $grants->issue($job, 1, 900);
        $journal = new UpdateJournal($this->db);
        $dispatcher = new RecoveryDispatcher(
            $this->db,
            $journal,
            $this->files->path,
            $storage . '/recovery',
            TestDatabase::driver(),
            false,
            null,
        );
        $gateway = new RecoveryGateway(
            $grants,
            $origin,
            static fn(Request $request, int $actor): bool => $actor === 1,
            $dispatcher->actions(),
        );
        $sequence = 0;
        $send = function (string $action) use ($gateway, $grant, $origin, &$sequence): array {
            $request = Request::create($origin . '/update-recovery.php', 'POST', [], [], [], [
                'HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'application/json',
                'HTTP_X_NOLVIKO_RECOVERY_GRANT' => $grant['id'], 'HTTP_X_NOLVIKO_RECOVERY_CODE' => $grant['code'],
                'HTTP_X_NOLVIKO_RECOVERY_NONCE' => $grant['nonce'],
                'HTTP_X_NOLVIKO_RECOVERY_SEQUENCE' => (string) $sequence,
            ], json_encode(['action' => $action], JSON_THROW_ON_ERROR));
            $response = $gateway->handle($request, []);
            $data = json_decode((string) $response->getContent(), true, 4, JSON_THROW_ON_ERROR);
            self::assertSame(200, $response->getStatusCode(), $action . ': ' . $response->getContent());
            $sequence = $data['next_sequence'];

            return $data;
        };

        self::assertSame('backup', $send('status')['next_action']);
        $send('backup');
        $state = $send('status');
        for ($round = 0; ($state['next_action'] ?? null) === 'resume' && $round < 50; $round++) {
            $send('resume');
            $state = $send('status');
        }
        self::assertSame(UpdateStage::FilesSwitched->value, $state['stage']);
        self::assertSame('migrate', $state['next_action'], 'the new runtime takes over from here');
        self::assertSame($entries['src/Core/Updates/SiteAccessLock.php'], file_get_contents(
            $this->files->path . '/src/Core/Updates/SiteAccessLock.php',
        ));
    }

    public function testServerFactsGateAndChangedInstalledFilesOrPendingMigrationsRefuseUpdate(): void
    {
        $metadata = ReleaseCompatibility::parse(json_encode(['format' => 1, 'version' => '1.1.0',
            'extension_api' => '1.0.0', 'theme_api' => 1, 'php' => '>=8.2.35 <8.6',
            'databases' => ['mysql' => '>=8.0 <10', 'mariadb' => '>=10.6 <13', 'postgresql' => '>=14 <19'],
            'extensions' => ['intl', 'zip'], 'packages' => []], JSON_THROW_ON_ERROR));
        $manifest = ReleaseSigningFixture::verify(ReleaseSigningFixture::body());
        $preflight = new UpdatePreflight(
            $this->db,
            $this->files->path,
            $this->private->path . '/storage',
            TestDatabase::driver()
        );
        self::assertSame([], $preflight->issues($metadata, $manifest, true));
        $internal = new UpdatePreflight(
            $this->db,
            $this->files->path,
            $this->files->path . '/storage',
            TestDatabase::driver(),
        );
        @unlink($this->files->path . '/storage/.htaccess');
        self::assertContains('update_private_storage_required', $internal->issues($metadata, $manifest, true));
        // Owner decision 2026-11-17: storage/ inside the application is fine while its deny-all .htaccess stands.
        file_put_contents($this->files->path . '/storage/.htaccess', "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n</IfModule>\n");
        self::assertNotContains('update_private_storage_required', $internal->issues($metadata, $manifest, true));
        file_put_contents($this->files->path . '/src/Core/Updates/SiteAccessLock.php', 'changed');
        self::assertContains('update_integrity_required', $preflight->issues($metadata, $manifest, true));
        $this->db->executeStatement("UPDATE migration_history SET status = 'pending' WHERE id = '0042_import_jobs'");
        self::assertContains('update_schema_required', $preflight->issues($metadata, $manifest, true));
        self::assertContains('update_confirmation_required', $preflight->issues($metadata, $manifest, false));
    }
}
