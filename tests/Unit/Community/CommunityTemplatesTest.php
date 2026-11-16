<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Community;

use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use PHPUnit\Framework\TestCase;

/** Real core templates compile and render both locales with hostile text escaped. */
final class CommunityTemplatesTest extends TestCase
{
    public function testEveryCommunitySectionRendersInBothLocales(): void
    {
        $root = dirname(__DIR__, 3);
        $urls = RouterFactory::create($root, '/missing-community-cache');
        $hostile = '<script>alert(1)</script>';

        foreach (['en', 'tr'] as $locale) {
            $views = TwigFactory::create(
                $root,
                '/missing-community-cache',
                TranslatorFactory::create($root . '/translations', $locale),
                urls: $urls
            );
            $profile = MemberProfiles::DEFAULTS + ['user_id' => 1, 'username' => $hostile];
            $profile['biography'] = $hostile;
            $profile['signature'] = $hostile;
            $data = ['thread' => ['title' => $hostile, 'first_post_id' => 1], 'poll' => null, 'options' => [],
                'posts' => [], 'extras' => [], 'relationship' => ['voted' => false], 'can_manage' => true,
                'solution' => null];

            foreach (['profile', 'settings', 'members', 'online', 'notifications', 'bookmarks', 'thread'] as $section) {
                $html = $views->render('community/page.twig', ['title' => 'test', 'section' => $section,
                    'error' => null, 'actor' => 1, 'profile' => $profile, 'items' => [], 'data' => $data,
                    'avatar' => str_repeat('a', 32), 'may_reset_avatar' => true, 'may_moderate' => true,
                    'older' => null, 'csrf_field' => '_csrf', 'csrf_token' => 'test', 'thread_id' => 1,
                    'user_id' => 1, 'blocks' => [], 'guest_requests' => 3]);
                self::assertStringContainsString('<h1>', $html);
                self::assertStringNotContainsString($hostile, $html);
                self::assertStringNotContainsString('>community.', $html);
            }
        }
    }
}
