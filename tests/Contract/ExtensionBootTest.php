<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Application\CompositionRoot;
use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Extensions\AuthChallenge;
use Nolviko\Core\Extensions\ComposerVersionConstraint;
use Nolviko\Core\Extensions\ExtensionBootstrap;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionHandlers;
use Nolviko\Core\Extensions\ExtensionRegistry;
use Nolviko\Core\Extensions\ExternalIdentity;
use Nolviko\Tests\Support\RecordingReporter;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Whole composition-root recovery and future authentication boundaries, without a database or active auth plugin. */
final class ExtensionBootTest extends TestCase
{
    public function testBrokenProviderDiscardsPartialRegistrationsAndBootsCoreInSafeMode(): void
    {
        $files = new TemporaryDirectory();
        try {
            $files->write('extensions/acme/broken/extension.json', json_encode([
                'id' => 'acme/broken', 'name' => 'Broken fixture', 'version' => '1.0.0', 'api' => 1,
                'engine' => '^1', 'php' => '^8.2', 'license' => 'MIT', 'providers' => ['src/Provider.php'],
            ], JSON_THROW_ON_ERROR));
            $files->write('extensions/acme/broken/src/Provider.php', '<?php throw new Exception("fixture_failure");');
            $manager = ExtensionBootstrap::manager($files->path, $files->path . '/storage');
            $manager->enable('acme/broken', static fn() => null);
            $container = CompositionRoot::build(
                $files->path,
                $files->path . '/storage',
                null,
                InstallationState::NotConfigured,
                new RecordingReporter(),
            );
            self::assertTrue($manager->state->safe());
            self::assertSame([], $container->get(ExtensionRegistry::class)->routes);
            self::assertCount(1, $manager->state->enabled());
        } finally {
            $files->remove();
        }
    }

    public function testSampleRouteUsesExplicitLazyDependenciesAndOwnedTranslation(): void
    {
        $files = new TemporaryDirectory();
        try {
            $sample = dirname(__DIR__) . '/Fixtures/extensions/acme/hello';
            foreach (
                ['extension.json', 'src/Provider.php', 'translations/en.json', 'translations/tr.json',
                'migrations/0001_greetings.json'] as $relative
            ) {
                $files->write(
                    'extensions/acme/hello/' . $relative,
                    (string) file_get_contents($sample . '/' . $relative),
                );
            }

            $files->writeCatalog('translations/en', 'core', ['core.fixture' => 'fixture']);
            $manager = ExtensionBootstrap::manager($files->path, $files->path . '/storage');
            $manager->enable('acme/hello', static fn() => null);
            // An old failure from an earlier boot (2026-11-19): a boot that succeeds forgets it.
            $manager->state->rememberFailure();
            $container = CompositionRoot::build(
                $files->path,
                $files->path . '/storage',
                null,
                InstallationState::NotConfigured,
                new RecordingReporter(),
            );
            $handlers = $container->get(ExtensionHandlers::class);
            self::assertNull($handlers->find('missing.route'));
            $handler = $handlers->find('acme.hello.index');
            self::assertNotNull($handler);
            $response = $handler->handle(Request::create('/extensions/acme/hello/index'), []);
            self::assertSame('Hello from the sample extension.', $response->getContent());
            self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
            self::assertFalse($manager->state->safe());
            self::assertFalse($manager->state->hasBootFailure());
        } finally {
            $files->remove();
        }
    }

    public function testCorruptStateStillLeavesCoreRecoveryAvailable(): void
    {
        $files = new TemporaryDirectory();
        try {
            $files->write('storage/extensions/enabled.json', 'not-json');
            $container = CompositionRoot::build(
                $files->path,
                $files->path . '/storage',
                null,
                InstallationState::NotConfigured,
                new RecordingReporter(),
            );
            self::assertSame([], $container->get(ExtensionRegistry::class)->routes);
            self::assertFileExists($files->path . '/storage/extensions/safe-mode');
        } finally {
            $files->remove();
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function versions(): iterable
    {
        yield 'caret' => ['1.2.3', '^1.0', true];
        yield 'downgrade' => ['1.0.0', '>=1.2', false];
        yield 'implicit Composer stability' => ['1.2.0-beta.1', '>=1.2.0', true];
        yield 'explicit stable floor' => ['1.2.0-beta.1', '>=1.2.0-stable', false];
        yield 'explicit prerelease' => ['1.2.0-beta.1', '>=1.2.0-beta.1 <2', true];
        yield 'tilde patch' => ['1.3.0', '~1.2.0', false];
        yield 'PHP alternatives' => ['8.5.12', '~8.4.27 || ~8.5.12', true];
    }

    #[DataProvider('versions')]
    public function testApprovedParserPreservesRangeSemantics(string $version, string $range, bool $expected): void
    {
        self::assertSame($expected, (new ComposerVersionConstraint())->accepts($version, $range));
    }

    public function testAuthenticationValuesAreExplicitlyProviderUserAndPurposeBound(): void
    {
        $challenge = new AuthChallenge('acme/factor', 7, 'admin_reauth', str_repeat('a', 64), 2000000000);
        self::assertSame(7, $challenge->userId);
        self::assertSame('admin_reauth', $challenge->purpose);
        $identity = new ExternalIdentity('acme/oauth', 'provider-subject', null);
        self::assertNull($identity->verifiedEmail);
        $this->expectException(ExtensionFailure::class);
        new AuthChallenge('acme/factor', 7, 'password_bypass', str_repeat('a', 64), 2000000000);
    }
}
