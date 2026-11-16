<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use DateTimeImmutable;
use Nolviko\Core\Updates\SignedReleaseManifest;

/**
 * Deterministic test-only signing seed; never a publisher key. Fixtures generate signatures without a network.
 */
final class ReleaseSigningFixture
{
    public const RELEASE = 'Nolviko-test-release';

    public const NOW = 1793620800;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function data(array $changes = []): array
    {
        $data = array_replace([
            'archive_bytes' => 1024,
            'archive_sha256' => str_repeat('a', 64),
            'channel' => 'stable',
            'expires_at' => self::NOW + 3600,
            'file_count' => 10,
            'format' => 1,
            'issued_at' => self::NOW - 60,
            'key_id' => 'test-key',
            'release_id' => self::RELEASE,
            'sequence' => 2,
            'unpacked_bytes' => 4096,
        ], $changes);
        ksort($data, SORT_STRING);

        return $data;
    }

    public static function key(): string
    {
        return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat('s', 32)));
    }

    public static function sign(string $body): string
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('s', 32)));

        return base64_encode(sodium_crypto_sign_detached($body, $secret));
    }

    /**
     * @param array<string, mixed> $changes
     */
    public static function body(array $changes = []): string
    {
        return json_encode(self::data($changes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function verify(string $body, ?string $signature = null): SignedReleaseManifest
    {
        return SignedReleaseManifest::verify(
            $body,
            $signature ?? self::sign($body),
            ['test-key' => self::key()],
            self::RELEASE,
            'stable',
            1,
            new DateTimeImmutable('@' . self::NOW),
        );
    }
}
