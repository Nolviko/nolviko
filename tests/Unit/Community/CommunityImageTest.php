<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Community;

use Nolviko\Modules\Community\Infrastructure\CommunityAccess;
use Nolviko\Modules\Community\Infrastructure\ImageProcessor;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Malformed input, pixel bombs, optional image support and metadata-free variants. */
final class CommunityImageTest extends TestCase
{
    public function testPngIsReencodedAndBounded(): void
    {
        $image = imagecreatetruecolor(600, 300);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);
        $result = (new ImageProcessor())->process($bytes, true);
        self::assertSame(256, $result['width']);
        self::assertSame(128, $result['height']);
        self::assertSame('image/png', $result['mime']);
        self::assertSame([256, 128], array_slice(getimagesizefromstring($result['bytes']), 0, 2));
        self::assertNotSame($bytes, $result['bytes']);
    }

    public function testJpegAndThumbnailAreIndependent(): void
    {
        $image = imagecreatetruecolor(1800, 600);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        unset($image);
        $result = (new ImageProcessor())->process($bytes, false);
        self::assertSame(1600, $result['width']);
        self::assertSame('image/jpeg', $result['mime']);
        self::assertSame(256, getimagesizefromstring($result['thumbnail'])[0]);
    }

    #[DataProvider('invalidImages')]
    public function testRejectsUnsafeImages(string $bytes): void
    {
        $this->expectException(RegistrationRejected::class);
        (new ImageProcessor())->process($bytes, false);
    }

    public static function invalidImages(): array
    {
        return [[''], ['<svg onload="alert(1)"></svg>'], ['<?php echo 1;'], ['https://127.0.0.1/image.png'],
            [base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')],
            [str_repeat('x', ImageProcessor::MAX_BYTES + 1)]];
    }

    #[DataProvider('invalidText')]
    public function testRejectsInvalidProfileText(string $text): void
    {
        $this->expectException(RegistrationRejected::class);
        CommunityAccess::text($text, 20);
    }

    public static function invalidText(): array
    {
        return [["invalid\0text"], ["\xff"], [str_repeat('ü', 21)]];
    }
}
