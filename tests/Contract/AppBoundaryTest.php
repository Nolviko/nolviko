<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * app/ is the whole product: the GitHub repository and a folder that runs when copied to a shared host.
 *
 * It must carry its own web-access rules and must not reference the surrounding workspace.
 */
final class AppBoundaryTest extends TestCase
{
    /** Directories that must never be served when app/ itself is the document root. */
    private const PRIVATE_DIRECTORIES = ['config', 'docker', 'docs', 'extensions',
         'src', 'storage', 'tests', 'themes', 'tools',
        'translations', 'vendor', 'views'];

    /**
     * @return list<array{0: string}>
     */
    public static function privateDirectories(): array
    {
        return array_map(static fn(string $directory): array => [$directory], self::PRIVATE_DIRECTORIES);
    }

    #[DataProvider('privateDirectories')]
    public function testPrivateDirectoryDeniesWebAccess(string $directory): void
    {
        $rules = (string) @file_get_contents(self::root() . '/' . $directory . '/.htaccess');

        self::assertStringContainsString('Require all denied', $rules, $directory);
        self::assertStringContainsString('Deny from all', $rules, $directory);
    }

    public function testRootServesOnlyPublicAndPublicRoutesToTheFrontController(): void
    {
        $root = (string) file_get_contents(self::root() . '/.htaccess');
        $public = (string) file_get_contents(self::root() . '/public/.htaccess');

        self::assertStringContainsString('RewriteRule ^(?!public/)(.*)$ public/$1 [L]', $root);
        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $public);
        self::assertStringContainsString('RewriteRule ^ index.php [L]', $public);
    }

    public function testEveryTopLevelDirectoryIsPublicOrPrivate(): void
    {
        $directories = array_map('basename', glob(self::root() . '/*', GLOB_ONLYDIR) ?: []);
        $unclassified = array_diff($directories, [...self::PRIVATE_DIRECTORIES, 'public', 'build']);

        self::assertSame([], array_values($unclassified), 'Add a deny .htaccess and list the directory here.');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function projectConfigFiles(): array
    {
        return [['composer.json'], ['phpunit.xml.dist'], ['phpcs.xml.dist'], ['compose.yaml'], ['Dockerfile'],
            ['.dockerignore']];
    }

    #[DataProvider('projectConfigFiles')]
    public function testProjectConfigurationStaysInsideApp(string $file): void
    {
        self::assertStringNotContainsString('../', (string) file_get_contents(self::root() . '/' . $file), $file);
    }

    public function testVendorIsCommittedAndSecretsAreNot(): void
    {
        $ignored = array_map('trim', file(self::root() . '/.gitignore') ?: []);

        self::assertNotContains('/vendor/', $ignored);
        self::assertContains('/.env', $ignored);
        self::assertContains('/storage/cache/', $ignored);
    }

    private static function root(): string
    {
        return (string) realpath(dirname(__DIR__, 2));
    }
}
