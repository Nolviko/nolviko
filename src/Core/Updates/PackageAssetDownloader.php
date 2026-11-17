<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/**
 * Downloads one uploaded release asset of an extension or theme for a panel update (2026-11-17, owner request:
 * update packages from the admin panel with the administrator's approval).
 *
 * Fixed origin: `https://github.com/<owner>/<repo>/releases/download/<tag>/<asset>`, then exactly one redirect, only to
 * GitHub's asset hosts. Verified TLS, no credentials, a byte cap and a deadline; the body must have exactly the size
 * the release API reported. Packages are not signed yet, so trust rests on the repository named in the installed
 * package's own manifest, HTTPS to GitHub and the administrator's confirmation.
 */
final class PackageAssetDownloader
{
    private const ASSET_HOSTS = ['release-assets.githubusercontent.com', 'objects.githubusercontent.com'];

    private const READ_TIMEOUT_SECONDS = 5.0;

    private const BODY_DEADLINE_SECONDS = 25.0;

    /**
     * @return string Path of a private temporary file holding the package; the caller deletes it.
     */
    public function download(
        ReleaseRepository $repository,
        string $tag,
        string $asset,
        int $expectedBytes,
        int $maxBytes,
    ): string {
        if (
            preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $tag) !== 1
            // A package, or a core release's signed manifest / signature next to it (ADR-037).
            || preg_match('/^[A-Za-z0-9._-]{1,128}\.zip(?:\.manifest\.json|\.sig)?$/D', $asset) !== 1
            || $expectedBytes < 1 || $expectedBytes > $maxBytes
            || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)
        ) {
            throw new RuntimeException('package_download_invalid');
        }

        $url = 'https://github.com/' . rawurlencode($repository->owner) . '/' . rawurlencode($repository->repository)
            . '/releases/download/' . rawurlencode($tag) . '/' . rawurlencode($asset);
        [$status, $headers] = $this->request($url, null);
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new RuntimeException('package_download_failed');
        }

        $location = (string) ($headers['location'] ?? '');
        $parts = parse_url($location);
        if (
            !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || !in_array(strtolower($parts['host'] ?? ''), self::ASSET_HOSTS, true)
        ) {
            throw new RuntimeException('package_download_redirect');
        }

        $file = tempnam(sys_get_temp_dir(), 'nolviko-package-');
        if ($file === false) {
            throw new RuntimeException('package_download_failed');
        }
        try {
            [$status] = $this->request($location, $file, $maxBytes);
            clearstatcache(true, $file);
            if ($status !== 200 || filesize($file) !== $expectedBytes) {
                throw new RuntimeException('package_download_size');
            }
        } catch (RuntimeException $failure) {
            @unlink($file);
            throw $failure;
        }

        return $file;
    }

    /**
     * @return array{0: int, 1: array<string, string>}
     */
    private function request(string $url, ?string $target, int $maxBytes = 0): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Nolviko', // i18n-ignore: HTTP header
                    'Accept: application/octet-stream', // i18n-ignore: HTTP header
                    'Connection: close', // i18n-ignore: HTTP header
                ],
                'timeout' => self::READ_TIMEOUT_SECONDS,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('package_download_failed');
        }

        $out = null;
        try {
            $meta = stream_get_meta_data($stream);
            $status = 0;
            $headers = [];
            foreach ($meta['wrapper_data'] ?? [] as $line) {
                if (preg_match('~^HTTP/\S+ ([0-9]{3})~', (string) $line, $match) === 1) {
                    $status = (int) $match[1];
                    $headers = [];
                } elseif (str_contains((string) $line, ':')) {
                    [$name, $value] = explode(':', (string) $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
            if ($target === null) {
                return [$status, $headers];
            }

            $out = fopen($target, 'wb');
            if ($out === false) {
                throw new RuntimeException('package_download_failed');
            }
            $written = 0;
            $deadline = microtime(true) + self::BODY_DEADLINE_SECONDS;
            while (!feof($stream)) {
                $chunk = @fread($stream, 65536);
                if ($chunk === false || microtime(true) > $deadline) {
                    throw new RuntimeException('package_download_failed');
                }
                $written += strlen($chunk);
                if ($written > $maxBytes || fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('package_download_size');
                }
            }

            return [$status, $headers];
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            fclose($stream);
        }
    }
}
