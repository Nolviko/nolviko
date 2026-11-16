<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Closure;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asks this web server over HTTP whether private files can be downloaded.
 *
 * `storage/` and `config/` get a random canary file (removed afterwards; a read-only `config/` is probed with
 * the source of `runtime-policy.php` instead); `vendor/` is probed with Composer's `installed.json`. The
 * request goes to the server's own address with the visitor's Host header, so it works behind port
 * mappings; nothing from the response is shown. A canary served back means exposed
 * (warning); 403/404 or any other body means protected; no answer, a redirect or a server error means the
 * check could not run (warning, with guidance to test manually). An origin that does not answer is not asked
 * again, so a single-worker host waits at most one timeout per candidate origin.
 */
final class WebExposureProbe
{
    public const EXPOSED = 'exposed';

    public const PROTECTED = 'protected';

    public const UNKNOWN = 'unknown';

    /** Short: a host with a single PHP worker cannot answer its own request until this one ends. */
    private const TIMEOUT_SECONDS = 1;

    private const VENDOR_FILE = 'vendor/composer/installed.json';

    private const VENDOR_MARKER = '"packages"';

    /** Probed when config/ is read-only: its source is visible only if PHP files are served as text. */
    private const CONFIG_FILE = 'config/runtime-policy.php';

    private const CONFIG_MARKER = 'function forumRuntimeErrors';

    private readonly Closure $fetch;

    /** @var array<int, string> Origins still worth asking during one probe(). */
    private array $origins = [];

    /**
     * @param (Closure(string $url, string $host): (array{0: int, 1: string}|null))|null $fetch Status and body,
     *     or null when no answer; null means a real HTTP request.
     */
    public function __construct(?Closure $fetch = null)
    {
        $this->fetch = $fetch ?? self::request(...);
    }

    /**
     * @return array<string, string> Directory (`storage`, `config`, `vendor`) => EXPOSED, PROTECTED or UNKNOWN.
     */
    public function probe(Request $request, string $root, string $storage): array
    {
        $this->origins = self::origins($request);
        $prefix = (string) preg_replace('~/public$~D', '', rtrim($request->getBasePath(), '/'));
        $host = $request->getHttpHost();
        $results = [];

        foreach (['storage' => $storage, 'config' => $root . '/config'] as $name => $directory) {
            $results[$name] = $this->probeCanary($host, $prefix . '/' . $name, $directory)
                ?? self::classify(
                    $this->fetch($prefix . '/' . self::CONFIG_FILE, $host),
                    static fn(string $body): bool => str_contains($body, self::CONFIG_MARKER),
                );
        }

        $results['vendor'] = self::classify(
            $this->fetch($prefix . '/' . self::VENDOR_FILE, $host),
            static fn(string $body): bool => str_contains($body, self::VENDOR_MARKER),
        );

        return $results;
    }

    /**
     * @return string|null Null when the canary cannot be written (read-only directory).
     */
    private function probeCanary(string $host, string $path, string $directory): ?string
    {
        $name = '.probe-' . bin2hex(random_bytes(8)) . '.txt';
        $content = bin2hex(random_bytes(16));

        if (
            !is_dir($directory) || @file_put_contents(
                $directory . '/' . $name,
                $content
            ) !== strlen($content)
        ) {
            @unlink($directory . '/' . $name);

            return null;
        }

        try {
            $answer = $this->fetch($path . '/' . $name, $host);
        } finally {
            @unlink($directory . '/' . $name);
        }

        return self::classify($answer, static fn(string $body): bool => trim($body) === $content);
    }

    /**
     * Tries the candidate origins until one answers and keeps only that one; with none left, asks nothing.
     *
     * @return array{0: int, 1: string}|null
     */
    private function fetch(string $path, string $host): ?array
    {
        foreach ($this->origins as $index => $origin) {
            $answer = ($this->fetch)($origin . $path, $host);

            if ($answer !== null) {
                $this->origins = [$origin];

                return $answer;
            }

            unset($this->origins[$index]);
        }

        return null;
    }

    /**
     * @param array{0: int, 1: string}|null $answer
     * @param Closure(string): bool $isPrivateContent
     */
    private static function classify(?array $answer, Closure $isPrivateContent): string
    {
        if ($answer === null || $answer[0] >= 500 || ($answer[0] >= 300 && $answer[0] < 400)) {
            return self::UNKNOWN;
        }

        return $answer[0] === 200 && $isPrivateContent($answer[1]) ? self::EXPOSED : self::PROTECTED;
    }

    /**
     * Probe the literal server address on the scheme's fixed port only. SERVER_PORT can be Host-derived.
     * Non-standard listeners require independent operator verification; never scan client-selected ports.
     *
     * @return list<string>
     */
    private static function origins(Request $request): array
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        $address = (string) $request->server->get('SERVER_ADDR', '127.0.0.1');
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return [];
        }
        $host = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $port = $scheme === 'https' ? 443 : 80;

        return [$scheme . '://' . $host . ':' . $port];
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private static function request(string $url, string $host): ?array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => ['Host: ' . $host, 'Connection: close'], // i18n-ignore: HTTP header names
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            // The server's own address rarely matches its certificate; only a random canary is compared.
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $context, 0, 65536);
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : null;

        $status = is_array($headers) && preg_match('~^HTTP/\S+\s+(\d{3})~', $headers[0] ?? '', $m) === 1
            ? (int) $m[1]
            : null;

        return $body === false || $status === null ? null : [$status, $body];
    }
}
