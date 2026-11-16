<?php

declare(strict_types=1);

namespace Nolviko\Modules\Gallery\Infrastructure;

use GdImage;
use Nolviko\Modules\Gallery\Domain\GalleryRejected;

/**
 * Gallery image decoding (2026-11-08): the real type comes from the bytes, never from the name or the browser.
 * Every image is decoded with GD within a memory budget and written again, so metadata (location, camera) and
 * anything hidden after the image data are dropped; animated GIFs keep their first frame. Output: a display copy
 * of at most DISPLAY pixels on the long side and a THUMB thumbnail.
 */
final class GalleryImages
{
    public const DISPLAY = 2560;

    public const THUMB = 480;

    /** Accepted types: extension => [GD image type, output MIME]. */
    public const TYPES = [
        'jpg' => [IMAGETYPE_JPEG, 'image/jpeg'],
        'jpeg' => [IMAGETYPE_JPEG, 'image/jpeg'],
        'png' => [IMAGETYPE_PNG, 'image/png'],
        'gif' => [IMAGETYPE_GIF, 'image/gif'],
        'webp' => [IMAGETYPE_WEBP, 'image/webp'],
    ];

    /**
     * @param list<string> $extensions The administrator's allowed extensions (subset of TYPES).
     * @return array{display: string, thumb: string, mime: string, width: int, height: int}
     *
     * @throws GalleryRejected `gallery_images_unavailable` or `gallery_type_refused`.
     */
    public function process(string $bytes, array $extensions): array
    {
        if (!extension_loaded('gd')) {
            throw new GalleryRejected('gallery_images_unavailable');
        }

        $info = @getimagesizefromstring($bytes);
        $types = [];

        foreach ($extensions as $extension) {
            if (isset(self::TYPES[$extension])) {
                $types[self::TYPES[$extension][0]] = self::TYPES[$extension][1];
            }
        }

        if (
            $info === false || !isset($types[$info[2]]) || $info[0] < 1 || $info[1] < 1
            || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 40000000
            || ($info[2] === IMAGETYPE_WEBP && !function_exists('imagewebp'))
        ) {
            throw new GalleryRejected('gallery_type_refused');
        }

        if (memory_get_usage(true) + strlen($bytes) * 2 + $info[0] * $info[1] * 10 + 33554432 > self::budget()) {
            throw new GalleryRejected('gallery_too_large_to_process');
        }

        $image = @imagecreatefromstring($bytes);

        if (!$image instanceof GdImage) {
            throw new GalleryRejected('gallery_type_refused');
        }

        try {
            [$display, $width, $height] = self::variant($image, $info[0], $info[1], self::DISPLAY, $info[2]);
            [$thumb] = self::variant($image, $info[0], $info[1], self::THUMB, $info[2]);
        } finally {
            unset($image);
        }

        return ['display' => $display, 'thumb' => $thumb, 'mime' => $types[$info[2]], 'width' => $width,
            'height' => $height];
    }

    private static function budget(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        $factor = match (strtolower(substr($limit, -1))) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };

        return $limit === '-1' ? 268435456 : (int) $limit * $factor;
    }

    /** @return array{string, int, int} */
    private static function variant(GdImage $source, int $width, int $height, int $maximum, int $type): array
    {
        $scale = min(1, $maximum / max($width, $height));
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start();

        try {
            $ok = match ($type) {
                IMAGETYPE_PNG => imagepng($target, null, 6),
                IMAGETYPE_GIF => imagegif($target),
                IMAGETYPE_WEBP => imagewebp($target, null, 85),
                default => imagejpeg($target, null, 85),
            };
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($target);
        }

        if (!$ok || !is_string($bytes) || $bytes === '') {
            throw new GalleryRejected('gallery_type_refused');
        }

        return [$bytes, $targetWidth, $targetHeight];
    }
}
