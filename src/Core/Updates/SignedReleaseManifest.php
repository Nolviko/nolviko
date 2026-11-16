<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Verified canonical release metadata. Only explicit publisher keys are trusted; no network key discovery.
 * This value authenticates one archive identity, not its eligibility or the safety of executing its code.
 */
final readonly class SignedReleaseManifest
{
    public const MAX_BODY_BYTES = 16384;

    public const MAX_ARCHIVE_BYTES = 33554432;

    public const MAX_UNPACKED_BYTES = 134217728;

    public const MAX_FILES = 10000;

    private const FIELDS = [
        'archive_bytes',
        'archive_sha256',
        'channel',
        'expires_at',
        'file_count',
        'format',
        'issued_at',
        'key_id',
        'release_id',
        'sequence',
        'unpacked_bytes',
    ];

    private const INTEGER_FIELDS = [
        'archive_bytes',
        'expires_at',
        'file_count',
        'format',
        'issued_at',
        'sequence',
        'unpacked_bytes',
    ];

    private function __construct(
        public string $releaseId,
        public int $sequence,
        public string $channel,
        public string $archiveHash,
        public int $archiveBytes,
        public int $unpackedBytes,
        public int $fileCount,
        public string $keyId,
        public string $manifestHash,
        public int $issuedAt,
        public int $expiresAt,
    ) {
    }

    /**
     * @param array<string, string> $trustedKeys Key ID => raw 32-byte Ed25519 public key, configured out of band.
     */
    public static function verify(
        string $body,
        string $signature,
        array $trustedKeys,
        string $expectedRelease,
        string $expectedChannel,
        int $installedSequence,
        DateTimeImmutable $now,
    ): self {
        if (!in_array($expectedChannel, ['stable', 'prerelease'], true) || $installedSequence < 0) {
            throw new RuntimeException('update_manifest_policy_invalid');
        }

        if (strlen($body) > self::MAX_BODY_BYTES || strlen($signature) !== 88) {
            throw new RuntimeException('update_manifest_invalid');
        }

        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('update_manifest_invalid');
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('update_manifest_invalid');
        }

        ksort($data, SORT_STRING);

        if (array_keys($data) !== self::FIELDS) {
            throw new RuntimeException('update_manifest_invalid');
        }

        foreach (self::INTEGER_FIELDS as $field) {
            if (!is_int($data[$field]) || $data[$field] < 0) {
                throw new RuntimeException('update_manifest_invalid');
            }
        }

        foreach (['archive_sha256', 'channel', 'key_id', 'release_id'] as $field) {
            if (!is_string($data[$field])) {
                throw new RuntimeException('update_manifest_invalid');
            }
        }

        if (
            $data['format'] !== 1
            || $data['sequence'] === 0
            || $data['archive_bytes'] === 0
            || $data['file_count'] === 0
            || $data['archive_bytes'] > self::MAX_ARCHIVE_BYTES
            || $data['unpacked_bytes'] > self::MAX_UNPACKED_BYTES
            || $data['file_count'] > self::MAX_FILES
            || preg_match('/^[a-f0-9]{64}$/D', $data['archive_sha256']) !== 1
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $data['key_id']) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $data['release_id']) !== 1
            || !in_array($data['channel'], ['stable', 'prerelease'], true)
        ) {
            throw new RuntimeException('update_manifest_invalid');
        }

        $canonical = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($canonical !== $body) {
            throw new RuntimeException('update_manifest_not_canonical');
        }

        $key = $trustedKeys[$data['key_id']] ?? null;

        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('update_signing_key_untrusted');
        }

        $detached = base64_decode($signature, true);

        if (
            $detached === false
            || strlen($detached) !== SODIUM_CRYPTO_SIGN_BYTES
            || base64_encode($detached) !== $signature
            || !sodium_crypto_sign_verify_detached($detached, $body, $key)
        ) {
            throw new RuntimeException('update_signature_invalid');
        }

        if ($data['release_id'] !== $expectedRelease || $data['channel'] !== $expectedChannel) {
            throw new RuntimeException('update_manifest_target_mismatch');
        }

        if ($data['sequence'] <= $installedSequence) {
            throw new RuntimeException('update_manifest_replayed');
        }

        $timestamp = $now->getTimestamp();

        if (
            $data['issued_at'] > $timestamp
            || $data['expires_at'] <= $timestamp
            || $data['expires_at'] <= $data['issued_at']
        ) {
            throw new RuntimeException('update_manifest_time_invalid');
        }

        return new self(
            $data['release_id'],
            $data['sequence'],
            $data['channel'],
            $data['archive_sha256'],
            $data['archive_bytes'],
            $data['unpacked_bytes'],
            $data['file_count'],
            $data['key_id'],
            hash('sha256', $body),
            $data['issued_at'],
            $data['expires_at'],
        );
    }
}
