<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Editor;

use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Editor\Domain\MarkdownToBbcode;
use PHPUnit\Framework\TestCase;

/**
 * Markdown subset written as BBCode (2026-11-08).
 */
final class MarkdownToBbcodeTest extends TestCase
{
    public function testBlocksAndInlineMarks(): void
    {
        $markdown = "# Title\nSome **bold**, *italic*, _also_, ~~gone~~ and `**raw**` text.\n"
            . "See [the docs](https://example.com/docs) or ![pic](https://example.com/a.png).\n"
            . "- one\n- two\n1. first\n2. second\n> quoted **line**\n```\ncode **kept**\n```\n5*3*2 stays";

        self::assertSame(
            "[size=large][b]Title[/b][/size]\nSome [b]bold[/b], [i]italic[/i], [i]also[/i], [s]gone[/s] and "
                . "`**raw**` text.\nSee [url=https://example.com/docs]the docs[/url] or "
                . "[img]https://example.com/a.png[/img].\n[list]\n[*]one\n[*]two\n[/list]\n[list=1]\n[*]first\n"
                . "[*]second\n[/list]\n[quote]quoted [b]line[/b][/quote]\n[code]code **kept**[/code]\n5*3*2 stays",
            MarkdownToBbcode::convert($markdown),
        );
    }

    public function testUnsafeLinksStayInertAfterRendering(): void
    {
        $html = (new BbcodeRenderer())->render(MarkdownToBbcode::convert('[x](javascript:alert(1)) <b>hi</b>'));

        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
    }
}
