<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Updates\GitHubReleaseDiscovery;
use Nolviko\Core\Updates\InstalledInventory;
use Nolviko\Core\Updates\PackageAssetDownloader;
use Nolviko\Core\Updates\RecoveryJobContext;
use Nolviko\Core\Updates\ReleaseCompatibility;
use Nolviko\Core\Updates\ReleaseVersion;
use Nolviko\Core\Updates\SafeReleaseArchive;
use Nolviko\Core\Updates\SignedReleaseManifest;
use Nolviko\Core\Updates\UpdatePreflightFailed;
use Nolviko\Core\Updates\UpdateStage;
use Nolviko\Core\Updates\UpdateTrust;
use RuntimeException;

/** Fresh-admin upload/staging coordinator. Local artifacts are authenticated before any target PHP may execute. */
final readonly class UpdatePreparation
{
    public function __construct(
        private Connection $db,
        private string $site,
        private string $storage,
        private string $driver,
        private ?GitHubReleaseDiscovery $discovery = null,
        private ?PackageAssetDownloader $downloader = null,
    ) {
    }

    /**
     * Downloads the newest eligible release with its signed manifest and signature from the trusted repository and
     * hands them to receive() (ADR-037, 2026-11-17): nothing is staged unless the signature verifies with a key in
     * config/update-trust.json. Assets: `<zip>`, `<zip>.manifest.json`, `<zip>.sig`.
     */
    public function download(int $actor, bool $confirmed): string
    {
        if ($actor < 1 || !$confirmed) {
            throw new RuntimeException('update_confirmation_required');
        }
        $trust = $this->trust();
        $result = ($this->discovery ?? throw new RuntimeException('update_discovery_unavailable'))
            ->check($trust->repository, $trust->channel, $this->now(), true);
        $installed = (new UpdatePreflight($this->db, $this->site, $this->storage, $this->driver))->current($trust);
        $release = ReleaseVersion::select($result->releases, $installed, $trust->channel)
            ?? throw new RuntimeException('update_not_newer');

        $assets = [];
        foreach ($release->assets as $asset) {
            $assets[$asset['name']] = $asset['bytes'];
        }
        $zip = null;
        foreach (array_keys($assets) as $name) {
            if (str_ends_with($name, '.zip') && isset($assets[$name . '.manifest.json'], $assets[$name . '.sig'])) {
                $zip = $name;
            }
        }
        if ($zip === null || $this->downloader === null) {
            throw new RuntimeException('update_signed_assets_missing');
        }

        $files = [];
        try {
            $caps = [
                $zip => SignedReleaseManifest::MAX_ARCHIVE_BYTES,
                $zip . '.manifest.json' => SignedReleaseManifest::MAX_BODY_BYTES,
                $zip . '.sig' => 128,
            ];
            foreach ($caps as $name => $cap) {
                $files[$name] = $this->downloader->download(
                    $trust->repository,
                    $release->tag,
                    $name,
                    $assets[$name],
                    $cap,
                );
            }

            return $this->receive(
                $files[$zip],
                (string) file_get_contents($files[$zip . '.manifest.json']),
                trim((string) file_get_contents($files[$zip . '.sig'])),
                $actor,
                true,
            );
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    public function configured(): bool
    {
        return UpdateTrust::load($this->site) !== null;
    }

    public function check(): ?string
    {
        $trust = $this->trust();
        // The administrator pressed "Check": ask GitHub now instead of answering from the hourly cache (owner
        // report 2026-11-17: a release published minutes after the last check stayed invisible). Failure backoff
        // still applies inside the discovery.
        $result = ($this->discovery ?? throw new RuntimeException('update_discovery_unavailable'))
            ->check($trust->repository, $trust->channel, $this->now(), true);
        $installed = (new UpdatePreflight($this->db, $this->site, $this->storage, $this->driver))->current($trust);
        return ReleaseVersion::select($result->releases, $installed, $trust->channel)?->tag;
    }

    public function receive(string $archive, string $body, string $signature, int $actor, bool $confirmed): string
    {
        if ($actor < 1 || !$confirmed || is_link($archive) || !is_file($archive)) {
            throw new RuntimeException('update_confirmation_required');
        }
        $trust = $this->trust();
        $manifest = $this->verify($body, $signature, $trust);
        $compatibility = ReleaseCompatibility::fromArchive($archive, $manifest);
        $this->approve($compatibility, $manifest);
        $journal = new UpdateJournal($this->db);
        // Check the private folder before opening a job, so a refusal never leaves a half-open update behind
        // (2026-11-17: community kept a "discovered" job after update_storage_invalid).
        $this->privateDirectory($this->storage . '/update-preparation');
        $job = $journal->begin($manifest->releaseId, $manifest->manifestHash, $this->now());
        $directory = $this->directory($job);
        $target = $directory . '/release.zip';
        $source = fopen($archive, 'rb');
        $output = fopen($target, 'xb');
        if ($source === false || $output === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            throw new RuntimeException('update_upload_failed');
        }
        try {
            if (
                !chmod($target, 0600)
                || stream_copy_to_stream($source, $output, SignedReleaseManifest::MAX_ARCHIVE_BYTES + 1)
                    !== $manifest->archiveBytes || !fflush($output)
            ) {
                throw new RuntimeException('update_upload_failed');
            }
        } finally {
            fclose($source);
            fclose($output);
        }
        if (!hash_equals($manifest->archiveHash, (string) hash_file('sha256', $target))) {
            throw new RuntimeException('update_archive_identity_invalid');
        }
        $json = json_encode(['actor' => $actor, 'body' => $body, 'signature' => $signature], JSON_THROW_ON_ERROR);
        if (
            file_put_contents($directory . '/request.json', $json, LOCK_EX) !== strlen($json)
            || !chmod($directory . '/request.json', 0600)
        ) {
            throw new RuntimeException('update_upload_failed');
        }
        return $job;
    }

    public function step(string $job, int $actor): array
    {
        [$data, $manifest] = $this->request($job, $actor);
        $journal = new UpdateJournal($this->db);
        $run = $journal->find($job) ?? throw new RuntimeException('update_not_found');
        if (
            $run->manifestHash !== $manifest->manifestHash
            || !in_array($run->stage, [UpdateStage::Discovered, UpdateStage::Checked, UpdateStage::Downloaded,
                UpdateStage::Verified, UpdateStage::Staged], true)
        ) {
            throw new RuntimeException('update_preparation_stage_invalid');
        }
        $archive = $this->directory($job) . '/release.zip';
        $compatibility = ReleaseCompatibility::fromArchive($archive, $manifest);
        $this->approve($compatibility, $manifest);
        if ($run->stage === UpdateStage::Staged) {
            return ['job' => $job, 'ready' => true, 'cursor' => 0];
        }
        $token = $journal->claim($job, $this->now());
        try {
            $run = $journal->find($job) ?? throw new RuntimeException('update_not_found');
            while ($run->stage !== UpdateStage::Verified) {
                $next = $run->stage->next() ?? throw new RuntimeException('update_preparation_stage_invalid');
                $run = $journal->advance($job, $token, $run->revision, $next, $this->now());
            }
            $extracted = (new SafeReleaseArchive($this->storage, $this->site . '/public'))
                ->step($archive, $manifest, $job);
            if ($extracted->complete) {
                $run = $journal->advance($job, $token, $run->revision, UpdateStage::Staged, $this->now());
            } else {
                $run = $journal->checkpoint($job, $token, $run->revision, $extracted->nextIndex, $this->now());
            }
            return ['job' => $job, 'ready' => $extracted->complete, 'cursor' => $extracted->nextIndex];
        } finally {
            $latest = $journal->find($job);
            if ($latest !== null) {
                $journal->release($job, $token, $latest->revision, $this->now());
            }
        }
    }

    public function publish(string $job, int $actor): bool
    {
        $directory = $this->directory($job);
        if (!is_file($directory . '/request.json')) {
            return false;
        }
        [$data, $manifest] = $this->request($job, $actor);
        $trust = $this->trust();
        $run = (new UpdateJournal($this->db))->find($job) ?? throw new RuntimeException('update_not_found');
        $context = $this->storage . '/recovery/jobs/' . $job . '.json';
        if (is_file($context) && !is_link($context)) {
            $existing = RecoveryJobContext::load($this->storage . '/recovery', $job);
            return hash_equals($manifest->manifestHash, $existing->manifest->manifestHash);
        }
        $archive = $directory . '/release.zip';
        $compatibility = ReleaseCompatibility::fromArchive($archive, $manifest);
        $this->approve($compatibility, $manifest);
        $this->privateDirectory($this->storage . '/update-backups');
        $this->privateDirectory($this->storage . '/updates');
        RecoveryJobContext::publish(
            $this->storage . '/recovery',
            $run,
            $data['body'],
            $data['signature'],
            $trust->keys,
            $manifest->channel,
            $this->floor(),
            $archive,
            $this->storage . '/updates/' . $job . '/stage',
            InstalledInventory::load($this->site),
            $this->storage . '/updates',
            $this->storage . '/update-backups',
            fn(SignedReleaseManifest $candidate): bool => $candidate->manifestHash === $manifest->manifestHash
                && (new UpdatePreflight($this->db, $this->site, $this->storage, $this->driver))
                    ->issues($compatibility, $candidate, true) === [],
            $compatibility->version->value,
        );
        return true;
    }

    public function abandon(string $job): void
    {
        if (
            file_exists($this->site . '/config/maintenance.lock')
            || is_link($this->site . '/config/maintenance.lock')
        ) {
            throw new RuntimeException('update_maintenance_owner_action_required');
        }
        $journal = new UpdateJournal($this->db);
        $token = $journal->claim($job, $this->now());
        $run = $journal->find($job) ?? throw new RuntimeException('update_not_found');
        try {
            $journal->abandon($job, $token, $run->revision, $this->now());
        } finally {
            $latest = $journal->find($job);
            if ($latest !== null && $latest->condition === 'running') {
                $journal->release($job, $token, $latest->revision, $this->now());
            }
        }
    }

    private function request(string $job, int $actor): array
    {
        $path = $this->directory($job) . '/request.json';
        if (is_link($path) || !is_file($path) || filesize($path) > 32768) {
            throw new RuntimeException('update_preparation_missing');
        }
        $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) || ($data['actor'] ?? null) !== $actor || !is_string($data['body'] ?? null)
            || !is_string($data['signature'] ?? null)
        ) {
            throw new RuntimeException('update_preparation_denied');
        }
        return [$data, $this->verify($data['body'], $data['signature'], $this->trust())];
    }

    private function approve(ReleaseCompatibility $compatibility, SignedReleaseManifest $manifest): void
    {
        if ($compatibility->version->channel() !== $manifest->channel) {
            throw new RuntimeException('update_channel_mismatch');
        }
        $issues = (new UpdatePreflight($this->db, $this->site, $this->storage, $this->driver))
            ->issues($compatibility, $manifest, true);
        if ($issues !== []) {
            throw new UpdatePreflightFailed($issues);
        }
    }

    private function verify(string $body, string $signature, UpdateTrust $trust): SignedReleaseManifest
    {
        $data = strlen($body) <= SignedReleaseManifest::MAX_BODY_BYTES ? json_decode($body, true, 4) : null;
        if (
            !is_array($data) || !is_string($data['release_id'] ?? null) || !is_string($data['channel'] ?? null)
            || ($trust->channel === 'stable' && $data['channel'] !== 'stable')
        ) {
            throw new RuntimeException('update_manifest_invalid');
        }
        return SignedReleaseManifest::verify(
            $body,
            $signature,
            $trust->keys,
            $data['release_id'],
            $data['channel'],
            $this->floor(),
            $this->now()
        );
    }

    private function floor(): int
    {
        return (int) ($this->db->fetchOne('SELECT release_sequence FROM installed_release WHERE id = 1') ?: 0);
    }

    private function trust(): UpdateTrust
    {
        return UpdateTrust::load($this->site) ?? throw new RuntimeException('update_trust_missing');
    }

    private function directory(string $job): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $job) !== 1) {
            throw new RuntimeException('update_identity_invalid');
        }
        $this->privateDirectory($this->storage . '/update-preparation');
        return $this->privateDirectory($this->storage . '/update-preparation/' . $job);
    }

    private function privateDirectory(string $path): string
    {
        $storage = realpath($this->storage);
        $public = realpath($this->site . '/public');
        // Owner decision 2026-11-17: storage/ itself may be 700 or 755 (both are normal after an upload). The
        // update's own folders below it are private: created 700, and tightened to 700 when PHP owns them.
        $normalize = static fn(string $value): string => strtolower(str_replace('\\', '/', $value));
        if (
            $storage === false || $public === false || is_link($this->storage)
            || str_starts_with($normalize($storage) . '/', $normalize($public) . '/')
        ) {
            throw new RuntimeException('update_storage_invalid');
        }
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700)) || !is_dir($path)) {
            throw new RuntimeException('update_storage_invalid');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077) !== 0 && fileowner($path) === getmyuid()) {
            @chmod($path, 0700);
            clearstatcache(true, $path);
        }
        return $path;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
