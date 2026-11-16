<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/**
 * Fixed-origin HTTPS GET, verified TLS, no redirects/authentication, bounded body and read deadline.
 */
final class NativeReleaseHttpClient implements ReleaseHttpClient
{
    public const API_VERSION = '2026-03-10';

    public const MAX_BODY_BYTES = 1048576;

    private const READ_TIMEOUT_SECONDS = 1.0;

    private const BODY_DEADLINE_SECONDS = 2.0;

    public static function safeEtag(?string $etag): ?string
    {
        return $etag !== null && preg_match('~^(?:W/)?"[A-Za-z0-9._:-]{1,160}"$~D', $etag) === 1 ? $etag : null;
    }

    public function fetch(ReleaseRepository $repository, ?string $etag): ReleaseHttpResponse
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('github_transport_unavailable');
        }

        $headers = [
            'Accept: application/vnd.github+json', // i18n-ignore: HTTP header
            'Accept-Encoding: identity', // i18n-ignore: HTTP header
            'User-Agent: Nolviko', // i18n-ignore: HTTP header
            'Connection: close', // i18n-ignore: HTTP header
            'X-GitHub-Api-Version: ' . self::API_VERSION,
        ];
        $etag = self::safeEtag($etag);

        if ($etag !== null) {
            $headers[] = 'If-None-Match: ' . $etag; // i18n-ignore: HTTP header
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => self::READ_TIMEOUT_SECONDS,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $stream = @fopen($repository->apiUrl(), 'rb', false, $context);

        if ($stream === false) {
            throw new RuntimeException('github_transport_failed');
        }

        try {
            $metadata = stream_get_meta_data($stream);
            $responseHeaders = $metadata['wrapper_data'] ?? [];
            $body = '';
            $deadline = microtime(true) + self::BODY_DEADLINE_SECONDS;

            while (!feof($stream)) {
                $chunk = @fread($stream, min(65536, self::MAX_BODY_BYTES + 1 - strlen($body)));

                if ($chunk === false || ($chunk === '' && !feof($stream)) || microtime(true) > $deadline) {
                    throw new RuntimeException('github_transport_failed');
                }

                $body .= $chunk;

                if (strlen($body) > self::MAX_BODY_BYTES) {
                    throw new RuntimeException('github_body_budget');
                }
            }
        } finally {
            fclose($stream);
        }

        $status = 0;
        $values = [];

        foreach ($responseHeaders as $header) {
            if (preg_match('~^HTTP/\S+ ([0-9]{3})~', $header, $match) === 1) {
                $status = (int) $match[1];
                $values = [];
            } elseif (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $values[strtolower(trim($name))] = trim($value);
            }
        }

        return new ReleaseHttpResponse(
            $status,
            $body,
            self::safeEtag($values['etag'] ?? null),
            self::integerHeader($values['retry-after'] ?? null),
            ($values['x-ratelimit-remaining'] ?? null) === '0'
                ? self::integerHeader($values['x-ratelimit-reset'] ?? null)
                : null,
        );
    }

    private static function integerHeader(?string $value): ?int
    {
        return $value !== null && preg_match('/^[0-9]{1,10}$/D', $value) === 1 ? (int) $value : null;
    }
}
