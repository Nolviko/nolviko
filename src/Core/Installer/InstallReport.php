<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Closure;
use Throwable;

/**
 * The one-time installation report (2026-11-27, roadmap "Tek seferlik kurulum bildirimi"): when the installer's
 * last step succeeds with its checkbox ticked, one POST with only the domain and the Nolviko version goes to a
 * fixed address on nolviko.com. The forum gets no endpoint, no schedule and no retry; a marker file in the config
 * folder makes a double click or a repeated finish send nothing more, and an unreachable receiver never stops
 * the installation.
 */
final readonly class InstallReport
{
    public const ENDPOINT = 'https://nolviko.com/api/install-report';
    public const MARKER = 'install-reported';

    /**
     * @param Closure(string, string): void|null $send Test seam: (url, JSON body).
     */
    public function __construct(
        private string $root,
        private string $configDirectory,
        private ?Closure $send = null,
    ) {
    }

    /** The installed version from the release's own metadata; null in a development checkout. */
    public function version(): ?string
    {
        $file = $this->root . '/config/release-compatibility.json';
        $data = is_file($file) && !is_link($file) ? json_decode((string) file_get_contents($file), true, 4) : null;

        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : null;
    }

    /** The host name of the request, lower case, without a port; null when it is not a usable name. */
    public static function domain(string $host): ?string
    {
        $host = strtolower(rtrim(preg_replace('~:\d+$~D', '', trim($host)) ?? '', '.'));

        return $host !== '' && strlen($host) <= 253 && preg_match('~^[a-z0-9.-]+$~D', $host) === 1 ? $host : null;
    }

    /** Sends the report at most once per installation; every failure is swallowed. */
    public function send(string $host): bool
    {
        $version = $this->version();
        $domain = self::domain($host);
        if ($version === null || $domain === null) {
            return false;
        }
        $marker = @fopen($this->configDirectory . '/' . self::MARKER, 'xb');
        if ($marker === false) {
            return false;
        }
        fclose($marker);
        $body = json_encode(['domain' => $domain, 'version' => $version], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            ($this->send ?? self::post(...))(self::ENDPOINT, $body);
        } catch (Throwable) {
            // A report only; the forum is installed either way.
        }

        return true;
    }

    private static function post(string $url, string $body): void
    {
        $headers = "Content-Type: application/json\r\nUser-Agent: Nolviko-Installer/1"; // i18n-ignore: HTTP header.
        $stream = @fopen($url, 'rb', false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 3,
            'follow_location' => 0, 'ignore_errors' => true, 'header' => $headers, 'content' => $body]]));
        if ($stream !== false) {
            fclose($stream);
        }
    }
}
