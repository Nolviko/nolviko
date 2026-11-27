<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\View;

use Nolviko\Core\Http\ThemeRequest;
use Nolviko\Core\View\TemplateException;
use Nolviko\Core\View\ThemeArchive;
use Nolviko\Core\View\ThemeAssetBuilder;
use Nolviko\Core\View\ThemeBuildStore;
use Nolviko\Core\View\ThemeCatalog;
use Nolviko\Core\View\ThemeChain;
use Nolviko\Core\View\ThemeManifest;
use Nolviko\Core\View\ThemePresentation;
use Nolviko\Core\View\ThemeTokens;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Actual filesystem/ZIP workflow, isolated from installed themes, accounts and published site data. */
final class ThemeWorkflowTest extends TestCase
{
    private TemporaryDirectory $files;

    private ThemeBuildStore $builds;

    protected function setUp(): void
    {
        $this->files = new TemporaryDirectory();
        $this->files->write('app/themes/default/theme.json', '{"id":"default","parent":null}');
        $this->files->write('app/views/layouts/page.twig', '<h1>{{ title }}</h1>{% block content %}{% endblock %}');
        $this->files->writeCatalog('app/translations/en', 'core', ['core.title' => 'Fixture']);
        $this->files->write('storage/marker', 'fixture');
        $this->builds = new ThemeBuildStore($this->files->path . '/app', $this->files->path . '/storage');
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    public function testDiscoveryIdentityAndThreeLevelInheritance(): void
    {
        $this->manifest('vendor.parent', 'default');
        $this->manifest('vendor.child', 'vendor.parent');
        self::assertSame(
            ['vendor.child', 'vendor.parent', 'default'],
            ThemeChain::resolve($this->builds->root . '/themes', 'vendor.child')->themes
        );
        self::assertCount(3, (new ThemeCatalog($this->builds->root . '/themes'))->all());
        $this->manifest('vendor.parent', 'vendor.child');
        $this->expectExceptionMessage('theme_parent_cycle:vendor.child');
        ThemeChain::resolve($this->builds->root . '/themes', 'vendor.child');
    }

    #[DataProvider('invalidManifests')]
    public function testManifestRejectsMalformedOrIncompatibleMetadata(array $extra): void
    {
        $this->expectException(TemplateException::class);
        ThemeManifest::parse(json_encode($extra + ['id' => 'vendor.test'], JSON_THROW_ON_ERROR), 'vendor.test');
    }

    public static function invalidManifests(): array
    {
        return [[['id' => 'other']], [['parent' => '../default']], [['version' => 'latest']], [['engine' => 2]],
            [['minimum_php' => '7.4']], [['assets' => ['../x.js']]], [['assets' => ['a.js', 'a.js']]],
            [['assets' => [false]]], [['slots' => ['post.header' => 2]]], [['unknown' => 'value']],
            [['upstream' => ['../file' => str_repeat('a', 64)]]]];
    }

    #[DataProvider('invalidTokens')]
    public function testTokenSchemaRefusesCodeAndLowContrast(array $tokens): void
    {
        $this->expectException(TemplateException::class);
        ThemeTokens::clean($tokens);
    }

    public static function invalidTokens(): array
    {
        return [[['light' => ['ink' => 'url(https://example.com)']]], [['dark' => ['paper' => '</style>']]],
            [['light' => ['ink' => '#ffffff']]], [['light' => ['unknown' => '#ffffff']]],
            [['width' => 1601]], [['radius' => -1]], [['spacing' => '16']], [['font' => 'remote-font']],
            [['density' => 'invalid']], [['custom_css' => 'body{}']]];
    }

    public function testBothDefaultPalettesMeetContrastAndEmitOnlyCompactCss(): void
    {
        $tokens = ThemeTokens::clean([]);
        foreach (['light', 'dark'] as $mode) {
            self::assertGreaterThanOrEqual(4.5, ThemeTokens::contrast($tokens[$mode]['ink'], $tokens[$mode]['paper']));
            self::assertGreaterThanOrEqual(
                4.5,
                ThemeTokens::contrast(
                    $tokens[$mode]['accent_ink'],
                    $tokens[$mode]['accent']
                )
            );
        }
        $css = ThemeTokens::css(['radius' => 12]);
        self::assertStringContainsString('--theme-radius:12px;', $css);
        self::assertStringNotContainsString("\n", $css);
    }

    public function testPrivatePreviewCannotLeakOrAlterThePublishedPointer(): void
    {
        $draft = $this->builds->build('default', ['radius' => 12], 1);
        $owner = new ArraySession();
        $owner->set('_user_id', 1);
        $presentation = new ThemePresentation($this->builds, $owner, new ThemeRequest());
        $presentation->preview(1, $draft['build']);
        self::assertTrue($presentation->selection()['preview']);
        self::assertSame($draft['build'], $presentation->selection()['hash']);
        self::assertNull($this->builds->pointer());
        $outsider = new ArraySession();
        $outsider->set('_user_id', 2);
        $outsider->set('_theme_preview', $owner->get('_theme_preview'));
        $outsider->set('_theme_preview_secret', $owner->get('_theme_preview_secret'));
        self::assertFalse((new ThemePresentation(
            $this->builds,
            $outsider,
            new ThemeRequest()
        ))->selection()['preview']);
        $original = $owner->get('_theme_preview');
        $expired = $original;
        $expired['expires'] = time() - 60;
        $expired['signature'] = hash_hmac(
            'sha256',
            '1:' . $expired['build'] . ':' . $expired['expires'],
            $owner->get('_theme_preview_secret')
        );
        $owner->set('_theme_preview', $expired);
        self::assertFalse($presentation->selection()['preview']);
        $owner->set('_theme_preview', $original);
        $token = $owner->get('_theme_preview');
        $token['build'] = str_repeat('a', 64);
        $owner->set('_theme_preview', $token);
        self::assertFalse($presentation->selection()['preview']);
        $presentation->stop();
        self::assertNull($owner->get('_theme_preview'));
    }

    public function testAtomicPublishStaleDraftRejectionAndRollback(): void
    {
        $first = $this->builds->build('default', ['radius' => 8], 1);
        $stale = $this->builds->build('default', ['radius' => 9], 2);
        $this->builds->publish(1, $first['build']);
        self::assertFalse($this->builds->hasPrevious());
        try {
            $this->builds->publish(2, $stale['build']);
            self::fail('stale draft published');
        } catch (TemplateException $failure) {
            self::assertSame('theme_draft_stale', $failure->getMessage());
        }
        $second = $this->builds->build('default', ['radius' => 10], 2);
        $this->builds->publish(2, $second['build']);
        self::assertTrue($this->builds->hasPrevious());
        self::assertSame($second['build'], $this->builds->active()['hash']);
        $this->builds->rollback($second['build']);
        self::assertSame($first['build'], $this->builds->active()['hash']);
    }

    public function testFailedCompilationKeepsLastGoodBuildAndCleansStage(): void
    {
        $good = $this->builds->build('default', [], 1);
        $this->builds->publish(1, $good['build']);
        $this->files->write('app/themes/default/views/layouts/page.twig', '{{ title|raw }}');
        try {
            $this->builds->build('default', [], 1);
            self::fail('unsafe template compiled');
        } catch (TemplateException) {
            self::assertSame($good['build'], $this->builds->active()['hash']);
        }
        self::assertSame([], glob($this->builds->directory() . '/stage-*'));
        self::assertSame($good['build'], $this->builds->draft(1)['build']);
    }

    public function testUpstreamDiffBlocksPublishingAndCoreUpdatesInvalidateOldBuild(): void
    {
        $this->manifest('vendor.custom', 'default');
        $this->files->write('app/themes/vendor.custom/views/layouts/page.twig', '<p>{{ title }}</p>');
        $draft = $this->builds->build('vendor.custom', [], 1);
        $warnings = $this->builds->get($draft['build'])['warnings'];
        self::assertSame('untracked', $warnings[0]['status']);
        self::assertStringContainsString('{{ title }}', $warnings[0]['upstream']);
        try {
            $this->builds->publish(1, $draft['build']);
            self::fail('unreviewed override published');
        } catch (TemplateException $failure) {
            self::assertSame('theme_publish_incompatible', $failure->getMessage());
        }
        $base = (string) file_get_contents($this->builds->root . '/views/layouts/page.twig');
        $this->manifest('vendor.custom', 'default', ['layouts/page.twig' => hash('sha256', $base)]);
        $reviewed = $this->builds->build('vendor.custom', [], 1);
        $this->builds->publish(1, $reviewed['build']);
        self::assertSame('vendor.custom', $this->builds->active()['theme']);
        $this->files->write('app/views/layouts/page.twig', $base . '<p>Updated core</p>');
        self::assertNull($this->builds->active());
        self::assertSame($reviewed['build'], $this->builds->pointer());
    }

    /** RC1 final run (2026-11-27): after a core update the published theme comes back, rebuilt once. */
    public function testACoreUpdateRebuildsTheActiveThemeWhenItStillFits(): void
    {
        $this->manifest('vendor.plain', 'default');
        $first = $this->builds->build('vendor.plain', [], 1);
        $this->builds->publish(1, $first['build']);
        $this->files->write('app/views/layouts/page.twig', '<h1>{{ title }}</h1><p>New core</p>'
            . '{% block content %}{% endblock %}');

        $active = $this->builds->active();

        self::assertNotNull($active);
        self::assertSame('vendor.plain', $active['theme']);
        self::assertNotSame($first['build'], $active['hash']);
        self::assertSame($active['hash'], $this->builds->pointer());
        self::assertSame($active['hash'], $this->builds->active()['hash'], 'built once');
    }

    public function testSourceZipRoundTripAndImmutableIds(): void
    {
        $archive = new ThemeArchive($this->builds);
        $source = $archive->export('default');
        $path = $this->files->write('roundtrip.zip', $source);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $zip->addFromString('theme.json', '{"id":"vendor.imported","parent":"default"}');
        $zip->close();
        self::assertSame('vendor.imported', $archive->import($path));
        self::assertNotNull((new ThemeCatalog($this->builds->root . '/themes'))->manifest('vendor.imported'));
        self::assertNull($this->builds->pointer(), 'import is not publication');
        // The same version again is not an update (2026-11-17).
        $this->expectExceptionMessage('theme_update_not_newer');
        $archive->import($path);
    }

    public function testNewerVersionReplacesAnInstalledThemeAndOlderIsRefused(): void
    {
        $archive = new ThemeArchive($this->builds);
        $source = $archive->export('default');
        $package = function (string $version, string $marker) use ($source): string {
            $path = $this->files->write('theme-' . $version . '.zip', $source);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            $manifest = '{"id":"vendor.updated","parent":"default","version":"' . $version . '"}';
            $zip->addFromString('theme.json', $manifest);
            $zip->addFromString('views/marker.twig', $marker);
            $zip->close();

            return $path;
        };
        $installed = $this->builds->root . '/themes/vendor.updated';

        $archive->import($package('1.0.0', 'one'));
        self::assertSame('1.0.0', ThemeArchive::installedVersion($installed));
        $archive->import($package('1.1.0', 'two'));
        self::assertSame('1.1.0', ThemeArchive::installedVersion($installed));
        self::assertSame('two', file_get_contents($installed . '/views/marker.twig'));
        self::assertSame([], glob($installed . '.old-*') ?: [], 'the old copy is removed');

        $this->expectExceptionMessage('theme_update_not_newer');
        $archive->import($package('1.0.5', 'older'));
    }

    #[DataProvider('unsafePaths')]
    public function testZipTraversalAndExecutableFilesAreRefused(string $name): void
    {
        $path = $this->files->path . '/bad.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('theme.json', '{"id":"vendor.bad","parent":"default"}');
        $zip->addFromString($name, '<?php echo 1;');
        $zip->close();
        $this->expectException(TemplateException::class);
        (new ThemeArchive($this->builds))->import($path);
    }

    public static function unsafePaths(): array
    {
        return [['../escape.twig'], ['/absolute.twig'], ['views/../../escape.twig'], ['views\\escape.twig'],
            ['views/file.php'], ['assets/file.svg'], ['theme.json/alternate'], ['C:/escape.twig']];
    }

    public function testCssCompactionPreservesQuotedTextAndJavascriptSemantics(): void
    {
        self::assertSame(
            'html{scroll-behavior: auto;}',
            ThemeAssetBuilder::build('theme.css', 'html { scroll-behavior: auto; }')
        );
        $source = "  .a :hover { content: 'a  b'; width: calc(100% - 2px); }  ";
        $css = ThemeAssetBuilder::build('theme.css', $source);
        self::assertStringContainsString(".a :hover{content: 'a  b';width: calc(100% - 2px);}", $css);
        self::assertLessThan(strlen($source), strlen($css));
        $js = "const r = /a  b/;\nconst t = `a  b`;\nreturn\n{};";
        self::assertSame($js . "\n", ThemeAssetBuilder::build('theme.js', $js));
        $this->expectException(TemplateException::class);
        ThemeAssetBuilder::build('theme.css', 'body { background: url(https://example.com); }');
    }

    public function testOnlyCoreFontStylesheetMayReferenceBundledWoff2(): void
    {
        $face = '@font-face { font-family: "F"; src: url("../fonts/figtree-latin.woff2") format("woff2"); }';
        self::assertStringContainsString('../fonts/figtree-latin.woff2', ThemeAssetBuilder::build('fonts.css', $face));
        $shipped = (string) file_get_contents(__DIR__ . '/../../../public/assets/fonts.css');
        self::assertStringContainsString('../fonts/', ThemeAssetBuilder::build('fonts.css', $shipped));
        foreach (
            [['theme.css', $face], ['fonts.css', str_replace('../fonts/', 'https://example.com/', $face)],
            ['fonts.css', str_replace('.woff2"', '.svg"', $face)], ['fonts.css', 'a { b: url(x); }']] as [$name, $css]
        ) {
            try {
                ThemeAssetBuilder::build($name, $css);
                self::fail($name . ' accepted ' . $css);
            } catch (TemplateException) {
                self::assertTrue(true);
            }
        }
        $tokens = ThemeTokens::css(ThemeTokens::clean([]));
        self::assertStringContainsString('--theme-font-display:"Bricolage Grotesque"', $tokens);
        self::assertStringNotContainsString('Figtree', ThemeTokens::css(ThemeTokens::clean(['font' => 'system'])));
    }

    public function testCompressedOversizeFileAndSymlinkEntriesAreRefused(): void
    {
        foreach ([false, true] as $link) {
            $path = $this->files->path . '/bounded-' . (int) $link . '.zip';
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString('theme.json', '{"id":"vendor.bad","parent":"default"}');
            $zip->addFromString('views/page.twig', $link ? '../outside' : str_repeat('a', 262145));
            if ($link) {
                $zip->setExternalAttributesName('views/page.twig', ZipArchive::OPSYS_UNIX, 0120777 << 16);
            }
            $zip->close();
            try {
                (new ThemeArchive($this->builds))->import($path);
                self::fail('oversize or symlink package accepted');
            } catch (TemplateException) {
                self::assertFalse(is_dir($this->builds->root . '/themes/vendor.bad'));
            }
        }
    }

    private function manifest(string $id, ?string $parent, array $upstream = []): void
    {
        $this->files->write('app/themes/' . $id . '/theme.json', json_encode(
            ['id' => $id, 'parent' => $parent, 'upstream' => $upstream],
            JSON_THROW_ON_ERROR,
        ));
    }
}
