<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Editor;

use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Editor\Domain\MediaEmbed;
use PHPUnit\Framework\TestCase;

/**
 * Click-to-load video cards (2026-11-08).
 */
final class MediaEmbedTest extends TestCase
{
    public function testSupportedAddresses(): void
    {
        self::assertSame(
            ['youtube', 'dQw4w9WgXcQ'],
            MediaEmbed::match('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5'),
        );
        self::assertSame(['youtube', 'dQw4w9WgXcQ'], MediaEmbed::match('https://youtu.be/dQw4w9WgXcQ'));
        self::assertSame(['youtube', 'dQw4w9WgXcQ'], MediaEmbed::match('https://m.youtube.com/shorts/dQw4w9WgXcQ'));
        self::assertSame(['vimeo', '76979871'], MediaEmbed::match('https://vimeo.com/76979871'));
        self::assertNull(MediaEmbed::match('https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'));
        self::assertNull(MediaEmbed::match('https://www.youtube.com/watch?v=short'));
        self::assertNull(MediaEmbed::match('javascript:alert(1)'));
        self::assertNull(MediaEmbed::match('https://vimeo.com/channels/staff'));
    }

    public function testOnlyALinkAloneOnItsLineBecomesACard(): void
    {
        $renderer = new BbcodeRenderer();
        $alone = $renderer->render("Look:\n  https://youtu.be/dQw4w9WgXcQ  \nnice");
        $inline = $renderer->render('Look at https://youtu.be/dQw4w9WgXcQ now');
        $explicit = $renderer->render('[url]https://youtu.be/dQw4w9WgXcQ[/url]');

        self::assertStringContainsString(
            '<div class="bb-embed" data-embed="youtube" data-embed-id="dQw4w9WgXcQ">',
            $alone,
        );
        self::assertStringContainsString('href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $alone);
        self::assertStringNotContainsString('iframe', $alone, 'nothing third-party is loaded by the markup');
        self::assertStringNotContainsString('bb-embed', $inline);
        self::assertStringNotContainsString('bb-embed', $explicit);
    }
}
