<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Community;

use Nolviko\Modules\Community\Domain\Mentions;
use PHPUnit\Framework\TestCase;

/**
 * @mention parsing (2026-11-08).
 */
final class MentionsTest extends TestCase
{
    public function testNamesQuotedNamesAndIgnoredPlaces(): void
    {
        $content = "Thanks @alice and @\"Ayşe Yılmaz\", also @ALICE again.\n"
            . "[quote=bob]@bob said @carol[/quote] mail me at me@example.com or see @dave.\n"
            . '[code]@root[/code] [quote][quote]@inner[/quote]@outer[/quote] @x';

        self::assertSame(['alice', 'Ayşe Yılmaz', 'dave'], Mentions::names($content));
    }

    public function testLinksAreAddedOnlyToTextOutsideLinksAndCode(): void
    {
        $url = static fn(string $name): string => '/members/' . rawurlencode($name);
        $html = '<p>Hi @alice and @&quot;Ayşe Yılmaz&quot;, mail me@example.com</p><a href="/x">@bob</a>'
            . '<pre class="bb-code"><code>@root</code></pre>';

        self::assertSame(
            '<p>Hi <a class="mention" href="/members/alice">@alice</a> and <a class="mention" '
                . 'href="/members/Ay%C5%9Fe%20Y%C4%B1lmaz">@&quot;Ayşe Yılmaz&quot;</a>, mail me@example.com</p>'
                . '<a href="/x">@bob</a><pre class="bb-code"><code>@root</code></pre>',
            Mentions::link($html, $url),
        );
    }

    public function testAtMostTenNames(): void
    {
        $names = Mentions::names(implode(' ', array_map(static fn(int $i): string => '@user' . $i, range(1, 15))));

        self::assertCount(Mentions::MAX, $names);
        self::assertSame('user1', $names[0]);
    }
}
