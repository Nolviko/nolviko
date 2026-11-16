<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\View;

use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\TemplateException;
use Nolviko\Core\View\ThemeChain;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Core\View\TwigRenderer;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Error\RuntimeError;

/**
 * M05.04: active theme → parent → default → core lookup, name/path validation and per-release compiled cache.
 */
final class ThemeResolutionTest extends TestCase
{
    private TemporaryDirectory $root;

    protected function setUp(): void
    {
        $this->root = new TemporaryDirectory();
        $this->theme('default', null, ['shared.twig' => 'default-shared', 'only-default.twig' => 'default-only']);
        $this->theme('base', 'default', ['shared.twig' => 'base-shared', 'only-base.twig' => 'base-only']);
        $this->theme('acme.child', 'base', [
            'shared.twig' => 'child-shared',
            'wrapped.twig' => "{% extends '@core/wrapped.twig' %}{% block body %}child+{{ parent() }}{% endblock %}",
        ]);
        $this->root->write('views/shared.twig', 'core-shared');
        $this->root->write('views/only-core.twig', 'core-only {{ value }}');
        $this->root->write('views/wrapped.twig', '[{% block body %}core{% endblock %}]');
        mkdir($this->root->path . '/storage');
    }

    protected function tearDown(): void
    {
        $this->root->remove();
    }

    public function testThreeLevelChainEndsWithDefault(): void
    {
        $chain = ThemeChain::resolve($this->root->path . '/themes', 'acme.child');

        self::assertSame(['acme.child', 'base', 'default'], $chain->themes);
        self::assertSame(['default'], ThemeChain::resolve($this->root->path . '/themes', 'default')->themes);
    }

    public function testMostSpecificTemplateWinsAndMissingOnesFallBack(): void
    {
        $renderer = $this->renderer('acme.child');

        self::assertSame('child-shared', $renderer->render('shared.twig'));
        self::assertSame('base-only', $renderer->render('only-base.twig'));
        self::assertSame('default-only', $renderer->render('only-default.twig'));
        self::assertSame('core-only &lt;b&gt;', $renderer->render('only-core.twig', ['value' => '<b>']));
        self::assertSame('core-shared', $renderer->render('@core/shared.twig'));
        self::assertSame('[child+core]', $renderer->render('wrapped.twig'), 'an override extends the core template');
    }

    public function testMissingCoreTemplateFailsLoudly(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('template_missing:nowhere.twig');

        $this->renderer('acme.child')->render('nowhere.twig');
    }

    public function testStrictVariablesFailInsteadOfRenderingEmpty(): void
    {
        $this->expectException(RuntimeError::class);

        $this->renderer('default')->render('only-core.twig');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNames(): array
    {
        return [
            'parent traversal' => ['../storage/secret.twig'],
            'inner traversal' => ['layouts/../../x.twig'],
            'dot dot inside a segment' => ['a..b.twig'],
            'absolute' => ['/etc/passwd.twig'],
            'drive letter' => ['C:/x.twig'],
            'backslash' => ['layouts\\page.twig'],
            'upper case' => ['Shared.twig'],
            'not twig' => ['shared.php'],
            'namespace traversal' => ['@core/../x.twig'],
            'nul byte' => ["shared.twig\0"],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testUnsafeTemplateNamesAreRefused(string $name): void
    {
        try {
            $this->renderer('default')->render($name);
            self::fail('accepted ' . $name);
        } catch (TemplateException $failure) {
            self::assertStringStartsWith('template_name_invalid:', $failure->getMessage());
        }
    }

    public function testSymlinkOutOfTheRootsIsRefused(): void
    {
        $outside = new TemporaryDirectory();
        $target = $outside->write('secret.twig', 'secret');

        if (!@symlink($target, $this->root->path . '/themes/default/views/linked.twig')) {
            $outside->remove();
            self::markTestSkipped('symlinks are not available on this filesystem');
        }

        try {
            $this->renderer('default')->render('linked.twig');
            self::fail('followed a symlink out of the theme root');
        } catch (TemplateException $failure) {
            self::assertSame('template_outside_roots:linked.twig', $failure->getMessage());
        } finally {
            unlink($this->root->path . '/themes/default/views/linked.twig');
            $outside->remove();
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function brokenChains(): array
    {
        return [
            'cycle' => ['loop.a', 'theme_parent_cycle:loop.a'],
            'missing parent' => ['orphan', 'theme_missing:gone'],
            'missing active' => ['absent', 'theme_missing:absent'],
            'invalid id' => ['../default', 'theme_id_invalid:../default'],
            'manifest id mismatch' => ['liar', 'theme_manifest_invalid:liar'],
            'manifest not json' => ['broken', 'theme_manifest_invalid:broken'],
            'too deep' => ['d9', 'theme_chain_too_deep:d9'],
        ];
    }

    #[DataProvider('brokenChains')]
    public function testBrokenChainsAreReportedWithTheirTheme(string $active, string $diagnostic): void
    {
        $this->theme('loop.a', 'loop.b');
        $this->theme('loop.b', 'loop.a');
        $this->theme('orphan', 'gone');
        $this->root->write('themes/liar/theme.json', '{"id": "someone-else", "parent": null}');
        $this->root->write('themes/broken/theme.json', '{"id": ');

        for ($depth = 1; $depth <= 9; ++$depth) {
            $this->theme('d' . $depth, $depth === 1 ? null : 'd' . ($depth - 1));
        }

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage($diagnostic);

        ThemeChain::resolve($this->root->path . '/themes', $active);
    }

    public function testCompiledTemplatesAreCachedPerRelease(): void
    {
        // One template per release: a template class compiled earlier in this process stays loaded (ADR-007
        // spike), so reusing one would skip compilation. Production starts each release in fresh processes.
        $this->renderer('default')->render('shared.twig');
        $this->root->write('config/release.php', "<?php return 'r1';");
        $this->renderer('default')->render('only-default.twig');
        $this->root->write('config/release.php', "<?php return 'r2';");
        $this->renderer('default')->render('wrapped.twig');

        $storage = $this->root->path . '/storage';

        foreach (['dev', 'r1', 'r2'] as $release) {
            $files = glob(TwigFactory::cacheDirectory($storage, $release === 'dev' ? null : $release) . '/*/*.php');
            self::assertNotEmpty($files ?: [], $release);
        }
    }

    public function testWithoutStorageNothingIsCached(): void
    {
        rmdir($this->root->path . '/storage');

        self::assertSame('default-shared', $this->renderer('default')->render('shared.twig'));
        self::assertDirectoryDoesNotExist($this->root->path . '/storage');
    }

    public function testUnwritableCacheLocationStillRenders(): void
    {
        // A file where the cache directory should be: mkdir fails as it would on a read-only storage.
        file_put_contents($this->root->path . '/storage/cache', 'not a directory');

        self::assertSame('default-shared', $this->renderer('default')->render('shared.twig'));
    }

    public function testShippedLayoutRendersThroughTheDefaultTheme(): void
    {
        $app = dirname(__DIR__, 3);
        $renderer = TwigFactory::create($app, $this->root->path . '/no-storage', self::translator());
        $html = $renderer->render('layouts/page.twig', ['language' => 'en', 'title' => '<Forum & co>']);

        self::assertStringContainsString('<title>&lt;Forum &amp; co&gt;</title>', $html);
        self::assertStringContainsString('<html lang="en" dir="ltr" data-appearance="system">', $html);
    }

    /**
     * @param array<string, string> $views
     */
    private function theme(string $id, ?string $parent, array $views = []): void
    {
        $this->root->write('themes/' . $id . '/theme.json', (string) json_encode(['id' => $id, 'parent' => $parent]));

        foreach ($views as $name => $source) {
            $this->root->write('themes/' . $id . '/views/' . $name, $source);
        }
    }

    private function renderer(string $active): TwigRenderer
    {
        $storage = $this->root->path . '/storage';

        return TwigFactory::create($this->root->path, $storage, self::translator(), activeTheme: $active);
    }

    private static function translator(): MessageTranslator
    {
        return TranslatorFactory::create(dirname(__DIR__, 3) . '/translations', TranslatorFactory::FALLBACK_LOCALE);
    }
}
