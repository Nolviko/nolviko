<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use Closure;
use DateTimeImmutable;
use RuntimeException;

/** Server-only handoff from release preparation. Never accepts browser paths, keys or eligibility decisions. */
final readonly class RecoveryJobContext
{
    private function __construct(public array $data, public SignedReleaseManifest $manifest)
    {
    }

    /**
     * $approve must enforce the selected release's dependency/channel/version policy outside this transport.
     * @param array<string, string> $keys Explicit publisher key ID => raw public key.
     * @param array<string, string> $installed Trusted previous core inventory, never a live-tree scan.
     */
    public static function publish(
        string $recovery,
        UpdateRun $run,
        string $body,
        string $signature,
        array $keys,
        string $channel,
        int $installedSequence,
        string $archive,
        string $staging,
        array $installed,
        string $swapRoot,
        string $backupRoot,
        Closure $approve,
        ?string $releaseVersion = null,
    ): void {
        $now = new DateTimeImmutable();
        $manifest = SignedReleaseManifest::verify(
            $body,
            $signature,
            $keys,
            $run->target,
            $channel,
            $installedSequence,
            $now,
        );

        if (
            $manifest->manifestHash !== $run->manifestHash
            || !in_array($run->stage, [UpdateStage::Staged, UpdateStage::BackedUp], true)
            || preg_match('/^[a-f0-9]{32}$/D', $run->id) !== 1 || $approve($manifest) !== true
        ) {
            throw new RuntimeException('recovery_release_not_approved');
        }

        if (
            $releaseVersion !== null && ReleaseCompatibility::fromArchive($archive, $manifest)->version->value
            !== (new ReleaseVersion($releaseVersion))->value
        ) {
            throw new RuntimeException('recovery_version_mismatch');
        }
        $plan = FileSwitchPlan::prepare($archive, $manifest, $staging, $installed);
        self::directory($recovery);
        $directory = $recovery . '/jobs';

        if (!file_exists($directory) && !mkdir($directory, 0700)) {
            throw new RuntimeException('recovery_context_write_failed');
        }

        self::directory($directory);
        $data = [
            'release_version' => $releaseVersion,
            'job' => $run->id, 'body' => $body, 'signature' => $signature,
            'keys' => array_map(base64_encode(...), $keys), 'release' => $run->target, 'channel' => $channel,
            'floor' => $installedSequence, 'verified_at' => $now->getTimestamp(),
            'archive' => $archive, 'staging' => $staging, 'installed' => $installed,
            'swap_root' => $swapRoot, 'backup_root' => $backupRoot,
            'manifest_hash' => $plan->manifestHash, 'plan_hash' => $plan->hash, 'operations' => $plan->operations,
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (strlen($json) > 4194304) {
            throw new RuntimeException('recovery_context_too_large');
        }

        $file = $directory . '/' . $run->id . '.json';
        $stream = fopen($file, 'x+b');

        if ($stream === false) {
            throw new RuntimeException('recovery_context_already_exists');
        }

        try {
            if (
                !chmod($file, 0600) || fwrite($stream, $json) !== strlen($json)
                || !fflush($stream) || !fsync($stream)
            ) {
                throw new RuntimeException('recovery_context_write_failed');
            }
        } finally {
            fclose($stream);
        }
    }

    public static function load(string $recovery, string $job): self
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $job) !== 1) {
            throw new RuntimeException('recovery_context_invalid');
        }

        self::directory($recovery);
        self::directory($recovery . '/jobs');
        $file = $recovery . '/jobs/' . $job . '.json';

        if (
            is_link($file) || !is_file($file) || filesize($file) > 4194304
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($file) & 0077) !== 0)
        ) {
            throw new RuntimeException('recovery_context_missing');
        }

        $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);

        if (
            !is_array($data) || ($data['job'] ?? null) !== $job || !is_array($data['keys'] ?? null)
            || !is_int($data['verified_at'] ?? null) || !is_int($data['floor'] ?? null)
        ) {
            throw new RuntimeException('recovery_context_invalid');
        }

        $keys = [];

        foreach ($data['keys'] as $id => $key) {
            $decoded = is_string($key) ? base64_decode($key, true) : false;

            if ($decoded === false) {
                throw new RuntimeException('recovery_context_invalid');
            }

            $keys[$id] = $decoded;
        }

        // Expiry was enforced at trusted preparation. Recovery of an already approved job must remain possible.
        $manifest = SignedReleaseManifest::verify(
            $data['body'],
            $data['signature'],
            $keys,
            $data['release'],
            $data['channel'],
            $data['floor'],
            new DateTimeImmutable('@' . $data['verified_at']),
        );

        if ($manifest->manifestHash !== ($data['manifest_hash'] ?? null)) {
            throw new RuntimeException('recovery_context_changed');
        }

        if (
            isset($data['release_version']) && (!is_string($data['release_version'])
            || ReleaseCompatibility::fromArchive($data['archive'], $manifest)->version->value
                !== $data['release_version'])
        ) {
            throw new RuntimeException('recovery_context_changed');
        }

        return new self($data, $manifest);
    }

    public function plan(): FileSwitchPlan
    {
        $plan = FileSwitchPlan::prepare(
            $this->data['archive'],
            $this->manifest,
            $this->data['staging'],
            $this->data['installed'],
        );

        if ($plan->hash !== $this->data['plan_hash'] || $plan->operations !== $this->data['operations']) {
            throw new RuntimeException('recovery_plan_changed');
        }

        return $plan;
    }

    private static function directory(string $path): void
    {
        if (
            is_link($path) || !is_dir($path)
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077) !== 0)
        ) {
            throw new RuntimeException('recovery_context_not_private');
        }
    }
}
