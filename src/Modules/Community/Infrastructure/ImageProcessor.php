<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use GdImage;
use Nolviko\Modules\Users\Domain\RegistrationRejected;

/** Optional GD, bounded decoding and metadata-free JPEG/PNG output. Never decodes SVG or animated images. */
final class ImageProcessor
{
    public const MAX_BYTES = 5242880;

    public function process(string $bytes, bool $avatar): array
    {
        if (!extension_loaded('gd')) {
            throw new RegistrationRejected(['community_images_unavailable']);
        }

        if (strlen($bytes) > self::MAX_BYTES || $bytes === '') {
            throw new RegistrationRejected(['community_upload_invalid']);
        }

        $info = @getimagesizefromstring($bytes);

        if (
            $info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096
            || $info[0] * $info[1] > 4000000
        ) {
            throw new RegistrationRejected(['community_upload_invalid']);
        }

        $limit = trim((string) ini_get('memory_limit'));
        $factor = match (strtolower(substr($limit, -1))) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };
        $budget = $limit === '-1' ? 134217728 : (int) $limit * $factor;

        if (memory_get_usage(true) + strlen($bytes) * 3 + $info[0] * $info[1] * 8 + 16777216 > $budget) {
            throw new RegistrationRejected(['community_upload_invalid']);
        }

        $image = @imagecreatefromstring($bytes);

        if (!$image instanceof GdImage) {
            throw new RegistrationRejected(['community_upload_invalid']);
        }

        try {
            $maximum = $avatar ? 256 : 1600;
            [$output, $width, $height] = $this->variant($image, $info[0], $info[1], $maximum, $info[2]);
            [$thumb] = $this->variant($image, $info[0], $info[1], 256, $info[2]);

            if (strlen($output) > self::MAX_BYTES || strlen($thumb) > self::MAX_BYTES) {
                throw new RegistrationRejected(['community_upload_invalid']);
            }

            return ['bytes' => $output, 'thumbnail' => $thumb, 'width' => $width, 'height' => $height,
                'mime' => $info[2] === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg'];
        } finally {
            unset($image);
        }
    }

    private function variant(GdImage $source, int $width, int $height, int $maximum, int $type): array
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
            $ok = $type === IMAGETYPE_PNG ? imagepng($target, null, 6) : imagejpeg($target, null, 85);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($target);
        }

        if (!$ok || !is_string($bytes)) {
            throw new RegistrationRejected(['community_upload_invalid']);
        }

        return [$bytes, $targetWidth, $targetHeight];
    }
}
