<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Editor;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Editor\Domain\UrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M03.13 XSS regression corpus: hostile vectors (OWASP filter-evasion style) in every BBCode context. Each
 * rendering is parsed as HTML and must contain only ADR-012 elements and attributes, only allowed URL schemes,
 * no event handlers, no inline style and no element the browser would execute.
 */
final class XssCorpusTest extends TestCase
{
    /** Element => allowed attributes (html/body come from the parser wrapper). */
    private const ALLOWED = [
        'html' => [], 'body' => [], 'strong' => [], 'em' => [], 'del' => [], 'br' => [], 'cite' => [], 'ul' => [],
        'ol' => [], 'li' => [], 'summary' => [], 'tr' => [], 'th' => [], 'td' => [],
        'span' => ['class'], 'blockquote' => ['class'], 'pre' => ['class'], 'code' => ['class'],
        'details' => ['class'], 'table' => ['class'], 'a' => ['href', 'rel'],
        'img' => ['src', 'alt', 'loading', 'referrerpolicy', 'class'],
    ];

    private const VECTORS = [
        'script tag' => '<script>alert(1)</script>',
        'img onerror' => '<img src=x onerror=alert(1)>',
        'svg onload' => '<svg onload=alert(1)>',
        'attribute breakout double' => '"><script>alert(1)</script>',
        'attribute breakout single' => "'><img src=x onerror=alert(1)>",
        'javascript scheme' => 'javascript:alert(1)',
        'mixed-case scheme' => 'JaVaScRiPt:alert(1)',
        'leading space scheme' => ' javascript:alert(1)',
        'tab in scheme' => "java\tscript:alert(1)",
        'newline in scheme' => "java\nscript:alert(1)",
        'nul in scheme' => "java\0script:alert(1)",
        'entity colon' => 'javascript&#58;alert(1)',
        'entity tab' => 'jav&#x09;ascript:alert(1)',
        'entity first letter' => '&#106;avascript:alert(1)',
        'data url' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
        'vbscript' => 'vbscript:msgbox(1)',
        'protocol-relative' => '//evil.example/x',
        'backslashes' => '\\\\evil.example\\x',
        'quote in url' => 'https://ok.example/" onmouseover="alert(1)',
        'single quote in url' => "https://ok.example/' onmouseover='alert(1)",
        'tag in url' => 'https://ok.example/<script>alert(1)</script>',
        'backtick in url' => 'https://ok.example/`onerror=alert(1)`',
        'css expression' => 'expression(alert(1))',
        'html link' => '<a href="javascript:alert(1)">x</a>',
        'iframe' => '<iframe src=javascript:alert(1)></iframe>',
        'body onload' => '<body onload=alert(1)>',
        'style import' => '<style>@import "https://evil.example/x.css";</style>',
        'math xlink' => '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
        'comment' => '<!--<script>alert(1)</script>-->',
        'cdata' => '<![CDATA[<script>alert(1)</script>]]>',
        'placeholder forgery' => "\u{E000}spoiler\u{E000}<script>alert(1)</script>",
        'userinfo trick' => 'https://good.example@evil.example/',
    ];

    private const CONTEXTS = [
        'text' => '%s',
        'url parameter' => '[url=%s]x[/url]',
        'url content' => '[url]%s[/url]',
        'url label' => '[url=https://ok.example]%s[/url]',
        'img' => '[img]%s[/img]',
        'quote author' => '[quote=%s]q[/quote]',
        'spoiler title' => '[spoiler=%s]s[/spoiler]',
        'code language' => '[code=%s]c[/code]',
        'code body' => '[code]%s[/code]',
        'color' => '[color=%s]c[/color]',
        'size' => '[size=%s]c[/size]',
        'list item' => '[list][*]%s[/list]',
        'table cell' => '[table][td]%s[/td][/table]',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function payloads(): array
    {
        $payloads = [];

        foreach (self::CONTEXTS as $context => $pattern) {
            foreach (self::VECTORS as $vector => $value) {
                $payloads[$vector . ' in ' . $context] = [sprintf($pattern, $value)];
            }
        }

        $payloads['nested url and img'] = ['[url=https://ok.example][img]javascript:alert(1)[/img][/url]'];
        $payloads['unclosed url swallowing markup'] = ['[url=https://ok.example]<script>alert(1)'];
        $payloads['closing tricks'] = ['[b]x[/b"><script>alert(1)</script>]'];
        $payloads['parameter with bracket'] = ['[url=https://ok.example]"]x[/url]'];

        return $payloads;
    }

    public function testCorpusIsLargeEnough(): void
    {
        self::assertGreaterThanOrEqual(100, count(self::payloads()));
    }

    #[DataProvider('payloads')]
    public function testPayloadRendersInert(string $source): void
    {
        $html = BbcodeRenderer::localize((new BbcodeRenderer())->render($source), 'Spoiler');

        self::assertSame([], self::violations($html), $html);
    }

    public function testTheOracleCatchesUnsafeMarkup(): void
    {
        $unsafe = [
            '<script>alert(1)</script>',
            '<a href="javascript:alert(1)" rel="nofollow ugc noopener">x</a>',
            '<img src="x" onerror="alert(1)">',
            '<span class="bb-u" style="color:red">x</span>',
            '<a href="https://ok.example" rel="opener">x</a>',
            '<iframe src="https://ok.example"></iframe>',
            '<span class="evil">x</span>',
        ];

        foreach ($unsafe as $html) {
            self::assertNotSame([], self::violations($html), $html);
        }

        $safe = '<strong>ok</strong><a href="https://ok.example" rel="nofollow ugc noopener">x</a>';

        self::assertSame([], self::violations($safe));
    }

    /**
     * @return list<string> Every element, attribute or value outside the ADR-012 output allowlist.
     */
    private static function violations(string $html): array
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $violations = [];

        foreach ((new DOMXPath($document))->query('//*') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $name = strtolower($element->nodeName);

            if (!array_key_exists($name, self::ALLOWED)) {
                $violations[] = 'element ' . $name;

                continue;
            }

            foreach ($element->attributes ?? [] as $attribute) {
                $attributeName = strtolower($attribute->nodeName);

                if (
                    !in_array($attributeName, self::ALLOWED[$name], true)
                    || !self::safeAttribute($attributeName, $attribute->nodeValue ?? '')
                ) {
                    $violations[] = $name . '@' . $attributeName . '=' . ($attribute->nodeValue ?? '');
                }
            }
        }

        return $violations;
    }

    private static function safeAttribute(string $attribute, string $value): bool
    {
        return match ($attribute) {
            'href' => UrlPolicy::allowed($value),
            'src' => UrlPolicy::allowed($value, UrlPolicy::IMAGE_SCHEMES),
            'class' => preg_match('/^(bb-[a-z0-9-]+|language-[a-z0-9+#-]+)$/D', $value) === 1,
            'rel' => $value === 'nofollow ugc noopener',
            'alt' => $value === '',
            'loading' => $value === 'lazy',
            'referrerpolicy' => $value === 'no-referrer',
            default => false,
        };
    }
}
