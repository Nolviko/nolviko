<?php

declare(strict_types=1);

namespace Nolviko\Modules\Content\Infrastructure;

use finfo;
use Nolviko\Modules\Content\Domain\ContentRejected;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Small public images the administrator adds (smileys and thread/post icons, ADR-042). Files are stored under
 * storage/site-images with a random key; the type is decided by finfo and getimagesize, never by the upload's
 * name or MIME. Only PNG, JPEG, GIF and WebP up to MAX_BYTES and MAX_SIDE pixels; SVG is never accepted. The key
 * changes with every file, so responses are cached as immutable.
 */
final class SiteImages
{
    public const MAX_BYTES = 262144;

    public const MAX_SIDE = 256;

    /** MIME => URL extension. */
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public function __construct(private readonly string $storage)
    {
    }

    /**
     * @return array{key: string, mime: string, width: int, height: int}
     *
     * @throws ContentRejected `content_image_invalid` or `content_image_failed`.
     */
    public function store(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ContentRejected('content_image_invalid', ['kb' => (string) (self::MAX_BYTES / 1024)]);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $size = @getimagesizefromstring($bytes);

        if (
            !is_string($mime) || !isset(self::TYPES[$mime]) || $size === false || ($size['mime'] ?? '') !== $mime
            || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE
        ) {
            throw new ContentRejected('content_image_invalid', ['kb' => (string) (self::MAX_BYTES / 1024)]);
        }

        $key = bin2hex(random_bytes(32));
        $path = $this->path($key);
        $stream = @fopen($path, 'xb');

        if ($stream === false) {
            throw new ContentRejected('content_image_failed');
        }

        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) {
                throw new ContentRejected('content_image_failed');
            }
        } finally {
            fclose($stream);
        }

        return ['key' => $key, 'mime' => $mime, 'width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    public function remove(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    /** Null when the key is malformed, the extension unknown or the file missing. */
    public function response(string $key, string $extension): ?BinaryFileResponse
    {
        $mime = array_search($extension, self::TYPES, true);

        if (!is_string($mime) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            return null;
        }

        $path = $this->path($key);

        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox"], public: true, autoEtag: false,
            autoLastModified: false);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }

    /** @throws ContentRejected `content_image_failed` for a malformed key or an unusable directory. */
    public function path(string $key): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new ContentRejected('content_image_failed');
        }

        $directory = rtrim($this->storage, '/\\') . '/site-images';

        foreach ([$directory, $directory . '/' . substr($key, 0, 2)] as $part) {
            if (is_link($part) || (!is_dir($part) && !@mkdir($part, 0700, true) && !is_dir($part))) {
                throw new ContentRejected('content_image_failed');
            }
        }

        return $directory . '/' . substr($key, 0, 2) . '/' . $key;
    }
}
