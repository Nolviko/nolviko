<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Seo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Nolviko\Modules\Community\Infrastructure\ImageProcessor;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Editor\Domain\UrlPolicy;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BetaSecurityCorpusTest extends TestCase
{
    #[DataProvider('payloads')]
    public function testHostileSourceCannotCreateExecutableDomOrUnsafeUrl(string $source): void
    {
        $html = (new BbcodeRenderer())->render($source);
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->loadHTML(
                '<!doctype html><html><body><div>' . $html . '</div></body></html>',
                LIBXML_NONET
            );
            $xpath = new DOMXPath($document);
            self::assertSame(
                0,
                $xpath->query('//script|//iframe|//object|//embed|//svg|//math|//style|//form')->length
            );
            foreach ($xpath->query('//*') as $element) {
                self::assertInstanceOf(DOMElement::class, $element);
                foreach ($element->attributes as $attribute) {
                    self::assertFalse(str_starts_with(strtolower($attribute->name), 'on'));
                    self::assertNotSame('srcdoc', strtolower($attribute->name));
                    if (in_array(strtolower($attribute->name), ['href', 'src'], true)) {
                        self::assertTrue(UrlPolicy::allowed(
                            $attribute->value,
                            strtolower($attribute->name) === 'src' ? UrlPolicy::IMAGE_SCHEMES : UrlPolicy::LINK_SCHEMES
                        ));
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function payloads(): array
    {
        return [
            ['<svg><a xlink:href="javascript:alert(1)">x</a></svg>'],
            ['<math><mtext><table><mglyph><style><!--</style><img '
                . 'title="--><img src=x onerror=alert(1)>">'],
            ['[url=javascript:alert(1)]x[/url]'],
            ['[url=jav&#x61;script:alert(1)]x[/url]'],
            ['[url=https://example.test/" onmouseover="alert(1)]x[/url]'],
            ['[img]data:image/svg+xml;base64,PHN2Zz4=[/img]'],
            ['[img]file:///etc/passwd[/img]'],
            ['[img]https://example.test/a" onerror="alert(1)[/img]'],
            ['[spoiler="><img src=x onerror=alert(1)>]x[/spoiler]'],
            ['[quote="><svg onload=alert(1)>]x[/quote]'],
            ['[table][tr][td]</div><script>alert(1)</script>[/td][/tr][/table]'],
            ['[code]<?php echo "never execute"; ?> <script>alert(1)</script>[/code]'],
            ["[url=java\0script:alert(1)]x[/url]"],
            ['[url=https://example.test/%22onload%3dalert(1)]safe encoded path[/url]'],
            ["[url=\u{202e}javascript:alert(1)]direction[/url]"],
        ];
    }

    #[DataProvider('invalidImages')]
    public function testExecutableOrUnsupportedImageBodiesAreRejected(string $bytes): void
    {
        $this->expectException(RegistrationRejected::class);
        (new ImageProcessor())->process($bytes, true);
    }

    public static function invalidImages(): array
    {
        return [['<?php echo "x"; ?>'], ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
            ['<!doctype html><script>alert(1)</script>'], ["GIF89a\x01\0\x01\0<?php echo 'x'; ?>"],
            ["PK\x03\x04archive"], [''], [str_repeat('x', ImageProcessor::MAX_BYTES + 1)]];
    }

    public function testValidImageIsReencodedAndPhpTrailerIsRemoved(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is optional; no-GD rejection is tested separately.');
        }
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        unset($image);
        $result = (new ImageProcessor())->process($png . '<?php echo "injected trailer"; ?>', true);
        self::assertSame('image/png', $result['mime']);
        self::assertStringNotContainsString('<?php', $result['bytes']);
        self::assertStringNotContainsString('injected trailer', $result['thumbnail']);
        self::assertSame(2, $result['width']);
        self::assertSame(2, $result['height']);
    }
}
