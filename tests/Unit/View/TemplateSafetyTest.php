<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\View;

use ArrayObject;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateException;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Core\View\TwigRenderer;
use Nolviko\Core\View\ViewExtension;
use Nolviko\Core\View\ViewModel;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M05.05: hostile input in text, attributes and URLs; no raw output; DTO-only contexts; CSP nonce.
 */
final class TemplateSafetyTest extends TestCase
{
    private const HOSTILE = '<script>alert(1)</script>" onmouseover="x" \' &amp;';

    private TemporaryDirectory $root;

    protected function setUp(): void
    {
        $this->root = new TemporaryDirectory();
        $this->root->write('themes/default/theme.json', '{"id": "default", "parent": null}');
        $templates = [
            'text' => '<p>{{ value }}</p>',
            'attribute' => '<a title="{{ value }}" data-x=\'{{ value }}\'>x</a>',
            'url' => '<a href="{{ value|safe_url }}">x</a>',
            'post' => '<div>{{ post.body }}</div>{{ post.title }}',
            'script' => '<script nonce="{{ csp_nonce }}">var v = "{{ value|e(\'js\') }}";</script>',
            'translated' => '{{ t("core.error.server.body", {incident: value}) }}',
            'raw' => '{{ value|raw }}',
            'apply-raw' => '{% apply raw %}{{ value }}{% endapply %}',
            'no-escape' => '{% autoescape false %}{{ value }}{% endautoescape %}',
            'js-block' => "{% autoescape 'js' %}{{ value }}{% endautoescape %}",
        ];

        foreach ($templates as $name => $source) {
            $this->root->write('views/' . $name . '.twig', $source);
        }
    }

    protected function tearDown(): void
    {
        $this->root->remove();
    }

    public function testTextIsEscaped(): void
    {
        $html = $this->renderer()->render('text.twig', ['value' => self::HOSTILE]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&amp;amp;', $html, 'entities are escaped, not trusted');
    }

    public function testQuotedAttributesCannotBeBrokenOutOf(): void
    {
        $html = $this->renderer()->render('attribute.twig', ['value' => self::HOSTILE]);

        self::assertStringNotContainsString('" onmouseover', $html);
        self::assertStringNotContainsString("' &", $html);
        self::assertStringContainsString('&quot; onmouseover=&quot;x&quot; &#039;', $html);
        self::assertSame(2, substr_count($html, '"'), 'only the template quotes remain');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function urls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)', '#'],
            'upper-case javascript' => ['JaVaScRiPt:alert(1)', '#'],
            'javascript with leading space' => [' javascript:alert(1)', '#'],
            'tab inside scheme' => ["java\tscript:alert(1)", '#'],
            'data' => ['data:text/html;base64,PHNjcmlwdD4=', '#'],
            'vbscript' => ['vbscript:msgbox', '#'],
            'protocol-relative' => ['//evil.example/x', '#'],
            'backslash trick' => ['/\\evil.example', '#'],
            'empty' => ['', '#'],
            'quote injection (space)' => ['/x" onclick="y', '#'],
            'quote without space is escaped' => ['/x"y', '/x&quot;y'],
            'https' => ['https://forum.example/t/1?a=1&b=2', 'https://forum.example/t/1?a=1&amp;b=2'],
            'mailto' => ['mailto:admin@forum.example', 'mailto:admin@forum.example'],
            'relative path' => ['/threads/1#post-2', '/threads/1#post-2'],
            'fragment' => ['#top', '#top'],
        ];
    }

    #[DataProvider('urls')]
    public function testUrlsWithScriptSchemesAreNeutralised(string $url, string $href): void
    {
        $html = $this->renderer()->render('url.twig', ['value' => $url]);

        self::assertSame('<a href="' . $href . '">x</a>', $html);
    }

    public function testSafeHtmlIsTheOnlyUnescapedOutput(): void
    {
        $post = new readonly class (new SafeHtml('<em>ok</em>'), '<i>title</i>') implements ViewModel {
            public function __construct(public SafeHtml $body, public string $title)
            {
            }
        };

        $html = $this->renderer()->render('post.twig', ['post' => $post]);

        self::assertStringContainsString('<div><em>ok</em></div>', $html);
        self::assertStringContainsString('&lt;i&gt;title&lt;/i&gt;', $html);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenTemplates(): array
    {
        return [
            'raw filter' => ['raw.twig', 'template_raw_forbidden:raw.twig'],
            'apply raw' => ['apply-raw.twig', 'template_raw_forbidden:apply-raw.twig'],
            'autoescape false' => ['no-escape.twig', 'template_autoescape_forbidden:no-escape.twig'],
        ];
    }

    #[DataProvider('forbiddenTemplates')]
    public function testRawOutputIsRejectedAtCompileTime(string $template, string $diagnostic): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($diagnostic);

        $this->renderer()->render($template, ['value' => '<b>']);
    }

    public function testOtherEscapingStrategiesStayAllowed(): void
    {
        $escaped = trim((string) json_encode('<b>', JSON_HEX_TAG), '"');

        self::assertSame($escaped, $this->renderer()->render('js-block.twig', ['value' => '<b>']));
    }

    public function testCspNonceAndJsEscapingInInlineScript(): void
    {
        $html = $this->renderer(new CspNonce('bm9uY2U9PQ=='))->render('script.twig', [
            'value' => '";alert(1)//</script>',
        ]);

        self::assertStringStartsWith('<script nonce="bm9uY2U9PQ==">', $html);
        self::assertSame(1, substr_count($html, '</script>'));
        self::assertStringNotContainsString('";alert', $html);
    }

    public function testTranslatedTextWithHostileParameterIsEscaped(): void
    {
        $html = $this->renderer()->render('translated.twig', ['value' => '<img src=x onerror=y>']);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function forbiddenContexts(): array
    {
        $mutable = new class implements ViewModel {
            public string $title = 'x';
        };
        $leaky = new readonly class (new ArraySession()) implements ViewModel {
            public function __construct(public object $session)
            {
            }
        };

        return [
            'session' => [['session' => new ArraySession()], 'view_context_invalid:session'],
            'closure' => [['run' => static fn(): int => 1], 'view_context_invalid:run'],
            'mutable object' => [['list' => new ArrayObject([1])], 'view_context_invalid:list'],
            'mutable view model' => [['page' => $mutable], 'view_context_invalid:page'],
            'service inside a DTO' => [['page' => $leaky], 'view_context_invalid:page.session'],
            'service inside an array' => [
                ['rows' => [['ok' => 1], ['db' => new ArraySession()]]],
                'view_context_invalid:rows.1.db',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('forbiddenContexts')]
    public function testTemplatesReceiveOnlyImmutableValues(array $context, string $diagnostic): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($diagnostic);

        $this->renderer()->render('text.twig', $context + ['value' => 'v']);
    }

    private function renderer(?CspNonce $nonce = null): TwigRenderer
    {
        $translator = TranslatorFactory::create(dirname(__DIR__, 3) . '/translations', 'en');

        return TwigFactory::create($this->root->path, $this->root->path . '/no-storage', $translator, $nonce);
    }
}
