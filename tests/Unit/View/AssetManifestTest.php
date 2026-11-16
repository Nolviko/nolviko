<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\View;

use Nolviko\Core\View\AssetManifest;
use Nolviko\Core\View\ViewExtension;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Static assets retain identical fingerprints across stages, change with content and reject unsafe paths.
 */
final class AssetManifestTest extends TestCase
{
    public function testFingerprintAndDevelopmentFallback(): void
    {
        $root = new TemporaryDirectory();
        $root->write('public/assets/site.css', 'body { color: black; }');
        $root->write('config/.keep', '');
        self::assertSame('assets/site.css', (new AssetManifest($root->path))->path('site.css'));
        $first = AssetManifest::build($root->path);
        $other = new TemporaryDirectory();
        $other->write('public/assets/site.css', 'body { color: black; }');
        $other->write('config/.keep', '');
        self::assertSame($first, AssetManifest::build($other->path));
        self::assertSame('assets/' . $first['site.css'], (new AssetManifest($root->path))->path('site.css'));
        self::assertSame(
            hash('sha256', 'body{color: black;}'),
            hash_file('sha256', $root->path . '/public/assets/' . $first['site.css']),
        );
        self::assertStringContainsString(hash('sha256', 'body{color: black;}'), $first['site.css']);
        $root->write('public/assets/site.css', 'body { color: blue; }');
        // A release builds from a clean source tree, not an already fingerprinted stage.
        $changed = new TemporaryDirectory();
        $changed->write('public/assets/site.css', 'body { color: blue; }');
        $changed->write('config/.keep', '');
        self::assertNotSame($first, AssetManifest::build($changed->path));
    }

    public function testTwigKeepsTheInstallationBasePath(): void
    {
        $root = new TemporaryDirectory();
        $root->write('public/assets/site.css', 'body {}');
        $twig = new Environment(new ArrayLoader(['page' => '{{ asset("site.css") }}']));
        $twig->addExtension(new ViewExtension(
            null,
            assets: new AssetManifest($root->path),
            assetBase: static fn(): string => '/forum/',
        ));
        self::assertSame('/forum/assets/site.css', $twig->render('page'));
    }

    public function testMissingFileFailsExplicitly(): void
    {
        $root = new TemporaryDirectory();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('asset_missing');
        (new AssetManifest($root->path))->path('missing.css');
    }

    public function testTraversalIsRejected(): void
    {
        $root = new TemporaryDirectory();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('asset_name_invalid');
        (new AssetManifest($root->path))->path('../config.js');
    }
}
