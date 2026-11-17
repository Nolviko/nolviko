<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/** Explicit owner-installed data-only trust policy. No bundled publisher key, browser trust or guessed version. */
final readonly class UpdateTrust
{
    public function __construct(
        public ReleaseRepository $repository,
        public ReleaseVersion $installed,
        public string $channel,
        public array $keys,
    ) {
        if (!in_array($channel, ['stable', 'prerelease'], true) || $keys === [] || count($keys) > 8) {
            throw new RuntimeException('update_trust_invalid');
        }
        foreach ($keys as $id => $key) {
            if (
                !is_string($id) || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id) !== 1
                || !is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ) {
                throw new RuntimeException('update_trust_invalid');
            }
        }
    }

    /**
     * The site owner's config/update-trust.json wins (never touched by updates). Otherwise the official trust that
     * ships with every release, config/release-trust.json (ADR-037, 2026-11-17): it is part of the signed package,
     * so a signed update can also deliver a rotated key.
     */
    public static function load(string $root): ?self
    {
        $path = $root . '/config/update-trust.json';
        if (!file_exists($path) && !is_link($path)) {
            $path = $root . '/config/release-trust.json';
        }
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        if (is_link($path) || !is_file($path) || filesize($path) > 8192) {
            throw new RuntimeException('update_trust_invalid');
        }
        $data = json_decode((string) file_get_contents($path), true, 8);
        if (
            !is_array($data) || array_diff(
                array_keys($data),
                ['format', 'owner', 'repository', 'installed_version', 'channel', 'keys']
            ) !== []
            || ($data['format'] ?? null) !== 1 || !is_array($data['keys'] ?? null)
            || !is_string($data['owner'] ?? null) || !is_string($data['repository'] ?? null)
            || !is_string($data['installed_version'] ?? null) || !is_string($data['channel'] ?? null)
        ) {
            throw new RuntimeException('update_trust_invalid');
        }
        $keys = [];
        foreach ($data['keys'] as $id => $encoded) {
            if (!is_string($encoded)) {
                throw new RuntimeException('update_trust_invalid');
            }
            $decoded = base64_decode($encoded, true);
            if ($decoded === false || base64_encode($decoded) !== $encoded) {
                throw new RuntimeException('update_trust_invalid');
            }
            $keys[$id] = $decoded;
        }
        return new self(
            new ReleaseRepository($data['owner'], $data['repository']),
            new ReleaseVersion($data['installed_version']),
            $data['channel'],
            $keys
        );
    }
}
