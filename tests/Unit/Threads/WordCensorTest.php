<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Threads;

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\PostingRules;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Settings\EffectivePostingRules;
use Nolviko\Modules\Posts\Domain\WordCensor;
use PHPUnit\Framework\TestCase;

/**
 * Word censor and the effective anti-spam rules (2026-11-08).
 */
final class WordCensorTest extends TestCase
{
    public function testWholeWordsInTextAreMaskedButMarkupIsNot(): void
    {
        $html = '<p>Darn it, <a href="https://darn.example/darn">DARN link</a> darned ŞEKER şeker</p>';

        self::assertSame(
            '<p>**** it, <a href="https://darn.example/darn">**** link</a> darned ***** *****</p>',
            WordCensor::html($html, ['darn', 'şeker', ' ']),
        );
        self::assertSame($html, WordCensor::html($html, []));
    }

    public function testConfigFileWinsOverStoredSettingsAndListsAreParsed(): void
    {
        self::assertSame(['a', 'b c'], EffectivePostingRules::words(' a, ,b c,a '));
        self::assertCount(EffectivePostingRules::MAX_ENTRIES, EffectivePostingRules::words(
            implode(',', range(1, 300)),
        ));

        $config = new Config(
            'mysql',
            'h',
            null,
            'n',
            'u',
            new Secret('p'),
            new Secret(str_repeat('s', 32)),
            postingRules: new PostingRules(newUserMaxLinks: 5, blockedWords: ['fromfile'])
        );
        $rules = EffectivePostingRules::of($config, null);

        self::assertSame(5, $rules->newUserMaxLinks);
        self::assertSame(['fromfile'], $rules->blockedWords);
        self::assertSame(PostingRules::DEFAULT_LINK_MIN_POSTS, $rules->linkMinPosts);
    }
}
