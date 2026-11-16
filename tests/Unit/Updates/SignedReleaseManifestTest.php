<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use DateTimeImmutable;
use Nolviko\Core\Updates\SignedReleaseManifest;
use Nolviko\Tests\Support\ReleaseSigningFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Detached signature, trust pinning, canonical encoding, strict schema, replay/time/channel and size policy.
 */
final class SignedReleaseManifestTest extends TestCase
{
    public function testTrustedSignatureProducesBoundedArchiveIdentity(): void
    {
        $body = ReleaseSigningFixture::body();
        $manifest = ReleaseSigningFixture::verify($body);
        self::assertSame(ReleaseSigningFixture::RELEASE, $manifest->releaseId);
        self::assertSame(str_repeat('a', 64), $manifest->archiveHash);
        self::assertSame(hash('sha256', $body), $manifest->manifestHash);
        self::assertSame(1024, $manifest->archiveBytes);
        self::assertSame(4096, $manifest->unpackedBytes);
        self::assertSame(10, $manifest->fileCount);
        self::assertSame(2, $manifest->sequence);
    }

    public function testModifiedContentCannotReuseTheOriginalSignature(): void
    {
        $original = ReleaseSigningFixture::body();
        $changed = ReleaseSigningFixture::body(['archive_sha256' => str_repeat('b', 64)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_signature_invalid');
        ReleaseSigningFixture::verify($changed, ReleaseSigningFixture::sign($original));
    }

    public function testUnknownKeyCannotBeSuppliedByTheManifest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_signing_key_untrusted');
        ReleaseSigningFixture::verify(ReleaseSigningFixture::body(['key_id' => 'attacker']));
    }

    public function testWrongTrustedKeyRejectsTheSignature(): void
    {
        $body = ReleaseSigningFixture::body();
        $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_signature_invalid');
        SignedReleaseManifest::verify(
            $body,
            ReleaseSigningFixture::sign($body),
            ['test-key' => $other],
            ReleaseSigningFixture::RELEASE,
            'stable',
            1,
            new DateTimeImmutable('@' . ReleaseSigningFixture::NOW),
        );
    }

    public function testNoConfiguredKeyFailsClosed(): void
    {
        $body = ReleaseSigningFixture::body();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_signing_key_untrusted');
        SignedReleaseManifest::verify(
            $body,
            ReleaseSigningFixture::sign($body),
            [],
            ReleaseSigningFixture::RELEASE,
            'stable',
            1,
            new DateTimeImmutable('@' . ReleaseSigningFixture::NOW),
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('invalidData')]
    public function testStrictManifestSchemaRejectsInvalidSignedData(array $changes): void
    {
        $this->expectException(RuntimeException::class);
        ReleaseSigningFixture::verify(ReleaseSigningFixture::body($changes));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidData(): array
    {
        return [
            'unknown field' => [['download_url' => 'https://attacker.example/file.zip']],
            'unsupported format' => [['format' => 2]],
            'string size' => [['archive_bytes' => '1024']],
            'float size' => [['archive_bytes' => 1024.5]],
            'negative size' => [['archive_bytes' => -1]],
            'zero archive' => [['archive_bytes' => 0]],
            'archive budget' => [['archive_bytes' => SignedReleaseManifest::MAX_ARCHIVE_BYTES + 1]],
            'unpacked budget' => [['unpacked_bytes' => SignedReleaseManifest::MAX_UNPACKED_BYTES + 1]],
            'file budget' => [['file_count' => SignedReleaseManifest::MAX_FILES + 1]],
            'empty file set' => [['file_count' => 0]],
            'malformed hash' => [['archive_sha256' => 'sha256:abc']],
            'release path traversal' => [['release_id' => '../release']],
            'key URL' => [['key_id' => 'https://attacker.example/key']],
            'key non-string' => [['key_id' => []]],
            'unknown channel' => [['channel' => 'nightly']],
            'zero sequence' => [['sequence' => 0]],
            'replay sequence' => [['sequence' => 1]],
            'wrong release' => [['release_id' => 'another-release']],
            'wrong channel' => [['channel' => 'prerelease']],
            'future issue time' => [['issued_at' => ReleaseSigningFixture::NOW + 1]],
            'expiry boundary' => [['expires_at' => ReleaseSigningFixture::NOW]],
            'expiry before issue' => [['expires_at' => ReleaseSigningFixture::NOW - 100]],
        ];
    }

    #[DataProvider('nonCanonicalBodies')]
    public function testEvenValidSignaturesCannotAuthorizeAmbiguousJson(string $body): void
    {
        $this->expectException(RuntimeException::class);
        ReleaseSigningFixture::verify($body);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCanonicalBodies(): array
    {
        $body = ReleaseSigningFixture::body();

        return [
            'whitespace' => [' ' . $body],
            'newline' => [$body . "\n"],
            'duplicate known key' => [str_replace('{', '{"format":1,', $body)],
            'array root' => ['[]'],
            'malformed' => ['{'],
            'oversized' => [str_repeat(' ', SignedReleaseManifest::MAX_BODY_BYTES + 1)],
        ];
    }

    #[DataProvider('badSignatures')]
    public function testBadSignatureEncodingIsRejected(string $signature): void
    {
        $this->expectException(RuntimeException::class);
        ReleaseSigningFixture::verify(ReleaseSigningFixture::body(), $signature);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badSignatures(): array
    {
        return [
            'missing' => [''],
            'invalid alphabet' => [str_repeat('!', 88)],
            'wrong signature' => [base64_encode(str_repeat('s', 64))],
            'truncated' => [base64_encode(str_repeat('s', 32))],
        ];
    }
}
