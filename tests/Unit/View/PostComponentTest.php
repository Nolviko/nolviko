<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\View;

use DateTimeImmutable;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Threads\Http\PostView;
use PHPUnit\Framework\TestCase;

/** The core post fragment shows the author's avatar and profile link only when the handler supplied them. */
final class PostComponentTest extends TestCase
{
    public function testAvatarAndProfileLinkAppearOnlyWhenGiven(): void
    {
        $app = dirname(__DIR__, 3);
        $views = TwigFactory::create(
            $app,
            sys_get_temp_dir() . '/nolviko-post-component',
            TranslatorFactory::create($app . '/translations', 'en'),
        );
        $base = [7, 'alice', new DateTimeImmutable('2026-11-07 10:00:00'), null, new SafeHtml('<p>Hi</p>'), null,
            '/posts/7'];

        $plain = $views->render('components/post.twig', ['post' => new PostView(...$base)]);
        $rich = $views->render('components/post.twig', ['post' => new PostView(
            ...[...$base, null, '/community/media/5', '/community/profile?user=3', '/threads/1/reply?quote=7'],
        )]);

        self::assertStringContainsString('<span class="av av-l', $plain);
        self::assertStringNotContainsString('<img', $plain);
        self::assertStringNotContainsString('post-acts', $plain);
        self::assertStringContainsString('<a href="/threads/1/reply?quote=7">Quote</a>', $rich);
        self::assertStringContainsString('<b class="post-author"><bdi>alice</bdi></b>', $plain);
        self::assertStringContainsString('<img class="av av-l" src="/community/media/5"', $rich);
        self::assertStringContainsString(
            '<a class="post-author" href="/community/profile?user=3"><bdi>alice</bdi></a>',
            $rich,
        );
    }
}
