<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\View;

use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateException;
use Nolviko\Core\View\ThemeSlots;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Core\View\TwigRenderer;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TrackingCache;
use PHPUnit\Framework\TestCase;

/** Cache hits cannot reuse a nonce, account, policy, locale, draft, palette or changed authorized content. */
final class ThemeIsolationTest extends TestCase
{
    private TemporaryDirectory $files;

    private TrackingCache $cache;

    protected function setUp(): void
    {
        $this->files = new TemporaryDirectory();
        $this->files->write('app/themes/default/theme.json', '{"id":"default"}');
        $this->files->write(
            'app/views/forums/index.twig',
            '<style nonce="{{ csp_nonce }}">h1{color:black}</style><h1>{{ title }}</h1>'
                . '{% if form is defined %}{{ form }}{% endif %}'
        );
        $this->files->write('app/views/components/post.twig', '<p>{{ post }}</p>');
        $this->files->writeCatalog('app/translations/en', 'core', ['core.test' => 'English']);
        $this->files->writeCatalog('app/translations/tr', 'core', ['core.test' => 'Türkçe']);
        $this->cache = new TrackingCache((new CacheFactory($this->files->path))->namespace('theme-isolation'));
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    public function testGuestCacheHitSubstitutesOnlyTheCurrentNonce(): void
    {
        $first = $this->renderer('first-request')->render('forums/index.twig', ['title' => '<private>']);
        $second = $this->renderer('second-request')->render('forums/index.twig', ['title' => '<private>']);
        self::assertSame(1, $this->cache->hits);
        self::assertSame(1, $this->cache->writes);
        self::assertStringContainsString('nonce="second-request"', $second);
        self::assertStringNotContainsString('first-request', $second);
        self::assertStringContainsString('&lt;private&gt;', $first . $second);
        self::assertStringNotContainsString('__NOLVIKO_CACHED_NONCE__', $second);
    }

    public function testNonceLookingPostTextIsPreservedOnFragmentCacheHits(): void
    {
        $text = new SafeHtml('<code>nonce="__NOLVIKO_CACHED_NONCE__"</code>');
        $this->renderer('first', ['user' => 1])->fragment('components/post.twig', ['post' => $text]);
        $hit = $this->renderer('second', ['user' => 1])->fragment('components/post.twig', ['post' => $text]);
        self::assertStringContainsString($text->html, $hit->html);
        self::assertSame(1, $this->cache->hits);
    }

    public function testPolicyLocalePaletteBuildAndContentInvalidateGuestHits(): void
    {
        $this->renderer('one')->render('forums/index.twig', ['title' => 'Public']);
        foreach (
            [['policy' => 'revoked'],
            ['locale' => 'tr'],
            ['mode' => 'dark'],
            ['theme' => 'new-build'], ['zone' => 'Europe/Istanbul'], ['base' => '/other/']] as $change
        ) {
            $html = $this->renderer('two', $change)->render('forums/index.twig', ['title' => 'Public']);
            self::assertStringContainsString('Public', $html);
        }
        $html = $this->renderer('three')->render('forums/index.twig', ['title' => 'Changed']);
        self::assertStringContainsString('Changed', $html);
        self::assertSame(0, $this->cache->hits);
        self::assertSame(8, $this->cache->writes);
    }

    public function testMembersCacheOnlyOwnFragmentsNeverTheirWholePages(): void
    {
        $this->renderer('one', ['user' => 1])->render('forums/index.twig', ['title' => 'Member page']);
        $this->renderer('two', ['user' => 1])->render('forums/index.twig', ['title' => 'Member page']);
        self::assertSame(0, $this->cache->writes);
        $first = $this->renderer('one', ['user' => 1])->fragment('components/post.twig', ['post' => 'Visible post']);
        $second = $this->renderer('two', ['user' => 1])->fragment('components/post.twig', ['post' => 'Visible post']);
        self::assertSame($first->html, $second->html);
        self::assertSame(1, $this->cache->hits);
        $this->renderer('three', ['user' => 2])->fragment('components/post.twig', ['post' => 'Visible post']);
        self::assertSame(1, $this->cache->hits);
        self::assertSame(2, $this->cache->writes);
    }

    public function testFormsSecretsPreviewsAndMutationRequestsBypassCache(): void
    {
        $this->renderer('one', ['preview' => true])->render('forums/index.twig', ['title' => 'Private preview']);
        $this->renderer('two', ['method' => 'POST'])->render('forums/index.twig', ['title' => 'Mutation']);
        $this->renderer('three')->render('forums/index.twig', ['title' => 'Public', 'csrf_token' => 'private']);
        $this->renderer('four')->render('forums/index.twig', [
            'title' => 'Form',
            'form' => new SafeHtml('<form><input name="sensitive"></form>'),
        ]);
        self::assertSame(0, $this->cache->writes);
        self::assertSame(0, $this->cache->hits);
    }

    public function testSlotsDenyBeforeCallingProvidersAndEnforceVersionContextAndOutput(): void
    {
        $calls = 0;
        $slots = new ThemeSlots(static fn(string $permission): bool => false);
        $slots->add('admin.dashboard.widgets', 1, 'fixture.admin', static function () use (&$calls): SafeHtml {
            ++$calls;
            return new SafeHtml('<p>Privileged</p>');
        });
        self::assertSame('', $slots->render('admin.dashboard.widgets')->html);
        self::assertSame(0, $calls);
        $slots->add('layout.header.before', 1, 'fixture.safe', static fn(): SafeHtml => new SafeHtml('<p>Safe</p>'));
        self::assertSame('<p>Safe</p>', $slots->render('layout.header.before')->html);
        $this->expectExceptionMessage('theme_slot_incompatible');
        $slots->add('layout.header.before', 2, 'fixture.invalid', static fn(): SafeHtml => new SafeHtml(''));
    }

    public function testSlotContextsCannotReceiveServicesOrUndeclaredFields(): void
    {
        $slots = new ThemeSlots();
        $this->expectExceptionMessage('theme_slot_context_invalid');
        $slots->render('layout.header.before', ['arbitrary' => $this]);
    }

    public function testTrustedSlotMarkupMustUseSafeHtml(): void
    {
        $slots = new ThemeSlots();
        $slots->add('layout.header.before', 1, 'fixture.unsafe', static fn(): string => '<script>bad</script>');
        $this->expectExceptionMessage('theme_slot_output_invalid');
        $slots->render('layout.header.before');
    }

    private function renderer(string $nonce, array $change = []): TwigRenderer
    {
        $identity = $change + ['user' => null, 'policy' => 'public', 'locale' => 'en', 'mode' => 'light',
            'theme' => 'default', 'preview' => false, 'method' => 'GET', 'zone' => 'UTC', 'base' => '/'];
        return TwigFactory::create(
            $this->files->path . '/app',
            $this->files->path,
            TranslatorFactory::create($this->files->path . '/app/translations', $identity['locale']),
            new CspNonce($nonce),
            renderCache: $this->cache,
            renderIdentity: static fn(): array => $identity
        );
    }
}
