<?php

declare(strict_types=1);

/*
 * Signs a Nolviko release ZIP (ADR-037, 2026-11-17). Run by .github/workflows/sign-release.yml:
 *   php .github/scripts/sign-release.php <release.zip> <stable|prerelease>
 * Reads the Ed25519 secret key (base64) from NOLVIKO_RELEASE_SIGNING_KEY and writes, next to the ZIP,
 * <zip>.manifest.json (the exact signed bytes, manifest format 1) and <zip>.sig (base64 detached signature).
 * The key is never printed. Not part of the release package (the packager only ships runtime folders).
 */

const KEY_ID = 'nolviko-release-1';
const VALID_SECONDS = 31536000;

[$zipPath, $channel] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_file($zipPath) || !in_array($channel, ['stable', 'prerelease'], true)) {
    fwrite(STDERR, "usage: sign-release.php <release.zip> <stable|prerelease>\n");
    exit(2);
}

$secret = base64_decode((string) getenv('NOLVIKO_RELEASE_SIGNING_KEY'), true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "NOLVIKO_RELEASE_SIGNING_KEY is missing or not a base64 Ed25519 secret key\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
    fwrite(STDERR, "cannot open the ZIP\n");
    exit(1);
}
$files = 0;
$unpacked = 0;
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $stat = $zip->statIndex($index);
    if ($stat !== false && !str_ends_with($stat['name'], '/')) {
        ++$files;
        $unpacked += $stat['size'];
    }
}
$releasePhp = (string) $zip->getFromName('config/release.php');
$zip->close();
if (preg_match("~return '([A-Za-z0-9][A-Za-z0-9._-]{0,79})';~", $releasePhp, $match) !== 1) {
    fwrite(STDERR, "config/release.php does not name the release\n");
    exit(1);
}

$now = time();
$manifest = [
    'archive_bytes' => (int) filesize($zipPath),
    'archive_sha256' => (string) hash_file('sha256', $zipPath),
    'channel' => $channel,
    'expires_at' => $now + VALID_SECONDS,
    'file_count' => $files,
    'format' => 1,
    'issued_at' => $now - 60,
    'key_id' => KEY_ID,
    'release_id' => $match[1],
    'sequence' => (int) gmdate('YmdHi', $now),
    'unpacked_bytes' => $unpacked,
];
ksort($manifest, SORT_STRING);
$body = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$public = sodium_crypto_sign_publickey_from_secretkey($secret);
$signature = base64_encode(sodium_crypto_sign_detached($body, $secret));
sodium_memzero($secret);

if (!sodium_crypto_sign_verify_detached((string) base64_decode($signature, true), $body, $public)) {
    fwrite(STDERR, "self-check failed\n");
    exit(1);
}
file_put_contents($zipPath . '.manifest.json', $body);
file_put_contents($zipPath . '.sig', $signature);
echo 'signed ', basename($zipPath), ' release ', $manifest['release_id'], ' sequence ', $manifest['sequence'], "\n";
