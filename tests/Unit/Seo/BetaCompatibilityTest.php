<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Seo;

use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Extensions\ComposerVersionConstraint;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionManifest;
use Nolviko\Core\Extensions\ExtensionResolver;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\SeoMetadata;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Core\View\TemplateException;
use Nolviko\Core\View\ThemeManifest;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BetaCompatibilityTest extends TestCase
{
    public static function supportedPhp(): array
    {
        return [['8.4.27'], ['8.4.28'], ['8.5.12'], ['8.5.13']];
    }

    #[DataProvider('supportedPhp')]
    public function testV1ExtensionAndThemeRemainCompatibleOnSupportedPhpBranches(string $php): void
    {
        $manifest = ExtensionManifest::parse(json_encode(['id' => 'acme/hello', 'name' => 'Fixture',
            'version' => '1.0.0',
            'api' => 1, 'engine' => '^1.0', 'php' => '~8.4.27 || ~8.5.12', 'license' => 'MIT',
            'packages' => ['twig/twig' => '^3.30']], JSON_THROW_ON_ERROR));
        $resolver = new ExtensionResolver(new ComposerVersionConstraint(), '1.0.0', $php, ['twig/twig'
            => '3.30.0']);
        self::assertSame([$manifest], $resolver->resolve(['acme/hello' => $manifest], ['acme/hello']));
        $theme = ThemeManifest::parse(
            json_encode(['id' => 'default', 'version' => '1.0.0', 'engine' => 1,
            'minimum_php' => '8.4.27', 'slots' => ['layout.header.after' => 1]], JSON_THROW_ON_ERROR),
            'default'
        );
        self::assertSame(1, $theme->data['engine']);
        self::assertTrue(version_compare($php, $theme->data['minimum_php'], '>='));
    }

    public function testIncompatibleSharedVendorCannotBeSilentlyLoaded(): void
    {
        $manifest = ExtensionManifest::parse(json_encode(['id' => 'acme/hello', 'name' => 'Fixture',
            'version' => '1.0.0',
            'api' => 1, 'engine' => '^1.0', 'php' => '^8.4', 'license' => 'MIT',
            'packages' => ['twig/twig' => '^4']], JSON_THROW_ON_ERROR));
        $this->expectException(ExtensionFailure::class);
        (new ExtensionResolver(new ComposerVersionConstraint(), '1.0.0', '8.4.27', ['twig/twig'
            => '3.30.0']))
            ->resolve(['acme/hello' => $manifest], ['acme/hello']);
    }

    public function testFutureThemeApiRequiresReviewInsteadOfACompatibilityGuess(): void
    {
        $this->expectException(TemplateException::class);
        ThemeManifest::parse('{"id":"default","version":"2.0.0",'
            . '"engine":2,"minimum_php":"8.4.27"}', 'default');
    }

    public function testCanonicalMetadataParticipatesInSharedGuestRenderCacheIdentity(): void
    {
        $root = new TemporaryDirectory();
        $app = dirname(__DIR__, 3);
        $root->write(
            'core/forums/index.twig',
            '<link rel="canonical" href="{{ seo.canonical|safe_url }}">'
        );
        $urls = RouterFactory::create($app, $root->path);
        $seo = new SeoMetadata(new SiteUrl('https://forum.example.test'));
        $views = TwigFactory::create(
            $app,
            $root->path,
            TranslatorFactory::create($app . '/translations', 'en'),
            CspNonce::generate(),
            coreViews: $root->path . '/core',
            renderCache: new FileCache($root->path . '/cache', $root->path),
            renderIdentity: static fn(): array => ['user' => null, 'preview' => false, 'method' => 'GET'],
            seo: $seo
        );
        try {
            $seo->bind(['_route' => CoreRoutes::THREAD_SHOW, 'slug' => 'a', 'thread' => '41'], $urls, false);
            self::assertStringContainsString('/threads/a-41', $views->render('forums/index.twig', ['title'
                => 'same']));
            $seo->bind(['_route' => CoreRoutes::THREAD_SHOW, 'slug' => 'b', 'thread' => '42'], $urls, false);
            $html = $views->render('forums/index.twig', ['title' => 'same']);
            self::assertStringContainsString('/threads/b-42', $html);
            self::assertStringNotContainsString('/threads/a-41', $html);
        } finally {
            $root->remove();
        }
    }
}
