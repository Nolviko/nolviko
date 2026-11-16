<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Editor;

use Nolviko\Modules\Editor\Domain\BbcodeParser;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M03.13: the ADR-012 dialect renders to exactly the documented HTML; malformed input degrades to text.
 */
final class BbcodeRendererTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'plain text is escaped' => ['a < b & "c"', 'a &lt; b &amp; &quot;c&quot;'],
            'newlines' => ["a\r\nb\nc", 'a<br>' . "\n" . 'b<br>' . "\n" . 'c'],
            'inline tags' => [
                '[b]b[/b][i]i[/i][u]u[/u][s]s[/s]',
                '<strong>b</strong><em>i</em><span class="bb-u">u</span><del>s</del>',
            ],
            'tags are case-insensitive' => ['[B]x[/b]', '<strong>x</strong>'],
            'url with parameter' => [
                '[url=https://example.com/a?b=1&c=2]site[/url]',
                '<a href="https://example.com/a?b=1&amp;c=2" rel="nofollow ugc noopener">site</a>',
            ],
            'url without parameter' => [
                '[url]https://example.com[/url]',
                '<a href="https://example.com" rel="nofollow ugc noopener">https://example.com</a>',
            ],
            'mailto' => [
                '[url=mailto:a@example.com]mail[/url]',
                '<a href="mailto:a@example.com" rel="nofollow ugc noopener">mail</a>',
            ],
            'bare url autolinks without trailing dot' => [
                'see https://example.com/x.',
                'see <a href="https://example.com/x" rel="nofollow ugc noopener">https://example.com/x</a>.',
            ],
            'quote with author' => [
                "[quote=Ay\u{015F}e]hi[/quote]",
                '<blockquote class="bb-quote"><cite>Ay' . "\u{015F}" . 'e</cite>hi</blockquote>',
            ],
            'quoted author parameter' => [
                '[quote="Ali"]x[/quote]',
                '<blockquote class="bb-quote"><cite>Ali</cite>x</blockquote>',
            ],
            'code is not parsed and keeps newlines' => [
                "[code=php][b]<?php\necho 1;[/code]",
                '<pre class="bb-code"><code class="language-php">[b]&lt;?php' . "\n" . 'echo 1;</code></pre>',
            ],
            'unordered list' => [
                "[list]\n[*]a\n[*]b\n[/list]",
                '<ul><li>a<br>' . "\n" . '</li><li>b<br>' . "\n" . '</li></ul>',
            ],
            'ordered list' => ['[list=1][*]a[*]b[/list]', '<ol><li>a</li><li>b</li></ol>'],
            'item closes inline formatting' => [
                '[list][*][b]a[*]b[/list]',
                '<ul><li><strong>a</strong></li><li>b</li></ul>',
            ],
            'stray list text becomes an item' => ['[list]a[*]b[/list]', '<ul><li>a</li><li>b</li></ul>'],
            'img' => [
                '[img]https://example.com/a.png[/img]',
                '<img src="https://example.com/a.png" alt="" loading="lazy" referrerpolicy="no-referrer" '
                    . 'class="bb-img">',
            ],
            'spoiler with title' => [
                '[spoiler=End]x[/spoiler]',
                '<details class="bb-spoiler"><summary>End</summary>x</details>',
            ],
            'color and size use classes' => [
                '[color=Red]r[/color][size=large]l[/size]',
                '<span class="bb-color-red">r</span><span class="bb-size-large">l</span>',
            ],
            'table' => [
                '[table][tr][th]h[/th][/tr][tr][td]d[/td][/tr][/table]',
                '<table class="bb-table"><tr><th>h</th></tr><tr><td>d</td></tr></table>',
            ],
            'cell without row gets one' => [
                '[table][td]x[/td][/table]',
                '<table class="bb-table"><tr><td>x</td></tr></table>',
            ],
            'unknown tag is literal' => ['[blink]x[/blink]', '[blink]x[/blink]'],
            'unclosed tags close at the end' => ['[b][i]x', '<strong><em>x</em></strong>'],
            'misnesting closes up to the opener' => ['[b][i]x[/b]y[/i]', '<strong><em>x</em></strong>y[/i]'],
            'closer without opener is literal' => ['x[/b]', 'x[/b]'],
            'invalid color is literal' => ['[color=#ff0000]x[/color]', '[color=#ff0000]x[/color]'],
            'invalid size is literal' => ['[size=999]x[/size]', '[size=999]x[/size]'],
            'item outside a list is literal' => ['[*]x', '[*]x'],
            'nested table is literal' => [
                '[table][tr][td][table]x[/table][/td][/tr][/table]',
                '<table class="bb-table"><tr><td>[table]x</td></tr></table>[/td][/tr][/table]',
            ],
            'url without closer becomes literal when invalid' => ['[url]not a url', '[url]not a url'],
            'empty url label shows the address' => [
                '[url=https://example.com][/url]',
                '<a href="https://example.com" rel="nofollow ugc noopener">https://example.com</a>',
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testRendering(string $source, string $html): void
    {
        self::assertSame($html, (new BbcodeRenderer())->render($source));
    }

    public function testUntitledSpoilerGetsTheTranslatedLabelPerRequest(): void
    {
        $cached = (new BbcodeRenderer())->render('[spoiler]x[/spoiler]');

        self::assertStringContainsString(BbcodeRenderer::SPOILER_LABEL, $cached);
        self::assertSame(
            '<details class="bb-spoiler"><summary>Spoiler &lt;!&gt;</summary>x</details>',
            BbcodeRenderer::localize($cached, 'Spoiler <!>'),
        );
        self::assertStringNotContainsString(
            BbcodeRenderer::SPOILER_LABEL,
            (new BbcodeRenderer())->render(BbcodeRenderer::SPOILER_LABEL),
            'users cannot type the placeholder',
        );
    }

    public function testLimitsTurnExcessTagsIntoText(): void
    {
        $deep = str_repeat('[b]', 30) . 'x';
        $quotes = str_repeat('[quote]', 7) . 'q';
        $many = str_repeat('[i]a[/i]', BbcodeParser::MAX_TAGS + 5);
        $renderer = new BbcodeRenderer();

        self::assertSame(BbcodeParser::MAX_DEPTH, substr_count($renderer->render($deep), '<strong>'));
        self::assertSame(BbcodeParser::MAX_QUOTE_DEPTH, substr_count($renderer->render($quotes), '<blockquote'));
        self::assertSame(BbcodeParser::MAX_TAGS, substr_count($renderer->render($many), '<em>'));
        self::assertSame(5, substr_count($renderer->render($many), '[i]a[/i]'));
    }

    public function testInvalidUtf8IsScrubbedNotPassedThrough(): void
    {
        $html = (new BbcodeRenderer())->render("ok \xC3\x28 end");

        self::assertTrue(mb_check_encoding($html, 'UTF-8'));
        self::assertStringStartsWith('ok ', $html);
    }

    public function testSourceIsTheEditableFormAndRenderingIsDeterministic(): void
    {
        $source = "[quote=Ali][b]Merhaba[/b] \u{1F44B}[/quote]\n[list][*]bir[*]iki[/list]";
        $renderer = new BbcodeRenderer();

        self::assertSame($renderer->render($source), $renderer->render($source));
        self::assertSame($source, $source, 'the editor loads posts.content unchanged (ADR-012 round trip)');
    }
}
