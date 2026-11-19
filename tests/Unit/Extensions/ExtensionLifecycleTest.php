<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Extensions;

use Nolviko\Core\Container\ContainerBuilder;
use Nolviko\Core\Extensions\BeforeHooks;
use Nolviko\Core\Extensions\ComposerVersionConstraint;
use Nolviko\Core\Extensions\ExtensionArchive;
use Nolviko\Core\Extensions\ExtensionBootstrap;
use Nolviko\Core\Extensions\ExtensionCatalog;
use Nolviko\Core\Extensions\ExtensionContext;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionManager;
use Nolviko\Core\Extensions\ExtensionManifest;
use Nolviko\Core\Extensions\ExtensionRegistry;
use Nolviko\Core\Extensions\ExtensionResolver;
use Nolviko\Core\Extensions\ExtensionState;
use Nolviko\Core\Extensions\HookDecision;
use Nolviko\Core\Extensions\PostingInput;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\View\ThemeSlots;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** M06 real-filesystem contracts: discovery is inert, activation is exact and failures preserve recovery. */
final class ExtensionLifecycleTest extends TestCase
{
    private TemporaryDirectory $files;

    private ExtensionManager $manager;

    protected function setUp(): void
    {
        $this->files = new TemporaryDirectory();
        $this->manager = new ExtensionManager(
            new ExtensionCatalog($this->files->path . '/extensions'),
            new ExtensionState($this->files->path . '/private'),
            new ExtensionResolver(new ComposerVersionConstraint(), '1.0.0', '8.4.27'),
        );
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    public function testDiscoveryDoesNotExecutePhpAndChecksIdentity(): void
    {
        $this->write(['providers' => ['src/Provider.php']]);
        $this->files->write('extensions/acme/hello/src/Provider.php', '<?php throw new Exception();');
        self::assertCount(1, $this->manager->catalog->discover()['manifests']);
        self::assertSame([], $this->manager->active());
        $this->files->write('extensions/acme/hello/extension.json', self::json(['id' => 'other/name']));
        self::assertSame('extension_identity_mismatch', $this->manager->catalog->discover()['errors']['acme/hello']);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'unknown key' => [['unknown' => true]];
        yield 'old API' => [['api' => 2]];
        yield 'bad ID' => [['id' => '../core']];
        yield 'traversal' => [['providers' => ['src/../bad.php']]];
        yield 'core permission' => [['permissions' => ['admin.access']]];
        yield 'invalid constraint' => [['engine' => 'banana']];
        yield 'bad dependency' => [['depends_on' => ['invalid' => '^1']]];
        yield 'bad version' => [['version' => 'latest']];
        yield 'bad locale' => [['translations' => ['../en']]];
    }

    #[DataProvider('invalid')]
    public function testManifestRejectsInvalidDeclarations(array $change): void
    {
        $this->expectException(ExtensionFailure::class);
        ExtensionManifest::parse(self::json($change));
    }

    public function testActivationIntegritySafeModeAndDisablePreserveFiles(): void
    {
        $this->write();
        $calls = 0;
        $this->manager->enable('acme/hello', static function () use (&$calls): void {
            ++$calls;
        });
        self::assertSame(1, $calls);
        self::assertCount(1, $this->manager->active());
        $this->manager->state->setSafe(true);
        self::assertSame([], $this->manager->active());
        $this->manager->resume();
        self::assertCount(1, $this->manager->active());
        $this->files->write('extensions/acme/hello/extra.txt', 'changed');
        self::assertSame('extension_integrity_changed', $this->manager->diagnostics()['runtime']);
        $this->manager->disableAll();
        self::assertSame([], $this->manager->active());
        self::assertFileExists($this->files->path . '/extensions/acme/hello/extension.json');
    }

    public function testFailedMigrationNeverEnablesPackage(): void
    {
        $this->write();
        try {
            $this->manager->enable('acme/hello', static function (): void {
                throw new ExtensionFailure('fixture_migration_failed');
            });
            self::fail('expected failure');
        } catch (ExtensionFailure $failure) {
            self::assertSame('fixture_migration_failed', $failure->getMessage());
        }

        self::assertSame([], $this->manager->state->enabled());
    }

    public function testDependencyOrderingConflictCyclesAndMissingPackage(): void
    {
        $a = ExtensionManifest::parse(self::json(['depends_on' => ['acme/base' => '^1']]));
        $b = ExtensionManifest::parse(self::json(['id' => 'acme/base']));
        $resolver = new ExtensionResolver(new ComposerVersionConstraint(), '1.0.0', '8.4.27');
        self::assertSame(
            [$b, $a],
            $resolver->resolve(['acme/hello' => $a, 'acme/base' => $b], ['acme/hello', 'acme/base']),
        );
        $this->expectExceptionMessage('extension_dependency_cycle');
        $b = ExtensionManifest::parse(self::json(['id' => 'acme/base', 'depends_on' => ['acme/hello' => '^1']]));
        $resolver->resolve(['acme/hello' => $a, 'acme/base' => $b], ['acme/hello', 'acme/base']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function incompatible(): iterable
    {
        yield 'engine' => [['engine' => '^2'], 'extension_incompatible'];
        yield 'php' => [['php' => '^9'], 'extension_incompatible'];
        yield 'dependency' => [['depends_on' => ['acme/missing' => '^1']], 'extension_dependency_missing'];
        yield 'package isolation' => [['packages' => ['composer/semver' => '^99']], 'extension_package_conflict'];
        yield 'conflict' => [['conflicts' => ['acme/hello' => '^1']], 'extension_conflict'];
    }

    #[DataProvider('incompatible')]
    public function testIncompatiblePackagesCannotEnable(array $change, string $diagnostic): void
    {
        $this->write($change);
        $this->expectExceptionMessage($diagnostic);
        $this->manager->enable('acme/hello', static fn() => null);
    }

    public function testSampleRegistersOwnedRoutesPermissionsSlotsTranslationsAndHook(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/extensions/acme/hello';
        foreach (
            ['extension.json', 'src/Provider.php', 'translations/en.json', 'translations/tr.json',
            'migrations/0001_greetings.json'] as $relative
        ) {
            $this->files->write(
                'extensions/acme/hello/' . $relative,
                (string) file_get_contents($fixture . '/' . $relative),
            );
        }

        $this->manager->enable('acme/hello', static fn() => null);
        $registry = new ExtensionRegistry();
        ExtensionBootstrap::register($this->manager, new ContainerBuilder(), $registry);
        self::assertCount(1, $registry->routes);
        self::assertCount(1, $registry->permissions);
        self::assertTrue($registry->applySlots(new ThemeSlots())->hasProviders());
        self::assertSame('body', $registry->before->posting(new PostingInput(1, 2, 'body')));
        $translator = $registry->translator(TranslatorFactory::create(dirname(__DIR__, 3) . '/translations', 'tr'));
        self::assertSame('Örnek eklentiden merhaba.', $translator->translate('acme.hello.greeting'));
    }

    public function testCoreNamespaceAndUndeclaredSlotsCannotRegister(): void
    {
        $manifest = ExtensionManifest::parse(self::json());
        $context = new ExtensionContext($manifest, new ContainerBuilder(), new ExtensionRegistry());
        $this->expectExceptionMessage('extension_namespace_invalid');
        $context->route(new RouteDefinition('home', '/', ['GET']), 'admin.access', static fn() => null);
    }

    public function testReplacementDenyAndSlowHooksAreBounded(): void
    {
        $hooks = new BeforeHooks();
        $hooks->add('acme/hello', static fn(PostingInput $input): HookDecision => HookDecision::replace('replacement'));
        self::assertSame('replacement', $hooks->posting(new PostingInput(1, 2, 'original')));
        $hooks->add(
            'acme/deny',
            static fn(PostingInput $input): HookDecision => HookDecision::deny('acme.deny.reason'),
        );
        $this->expectExceptionMessage('extension_hook_denied:acme.deny.reason');
        $hooks->posting(new PostingInput(1, 2, 'original'));
    }

    public function testUnicodePostingLimitIsNotNarrowedByHooks(): void
    {
        $content = str_repeat("\u{1F642}", 50000);
        self::assertNotNull(CreateThread::content($content));
        self::assertSame($content, (new BeforeHooks())->posting(new PostingInput(1, 2, $content)));
    }

    public function testTableNamespacesAreUnambiguousAndFitLongExtensionIds(): void
    {
        $first = ExtensionManifest::parse(self::json(['id' => 'acme_hello/example']));
        $second = ExtensionManifest::parse(self::json(['id' => 'acme/hello_example']));
        self::assertNotSame($first->table('records'), $second->table('records'));
        $long = ExtensionManifest::parse(self::json(['id' => str_repeat('a', 32) . '/' . str_repeat('b', 32)]));
        self::assertLessThanOrEqual(63, strlen($long->table(str_repeat('c', 26))));
        $this->expectExceptionMessage('extension_namespace_invalid');
        $first->table('../users');
    }

    public function testMissingProviderIsRejectedBeforeActivation(): void
    {
        $this->write(['providers' => ['src/Missing.php']]);
        $this->expectExceptionMessage('extension_path_invalid');
        $this->manager->enable('acme/hello', static fn() => null);
    }

    public function testSlowHookFailsClosed(): void
    {
        $hooks = new BeforeHooks();
        $hooks->add('acme/slow', static function (PostingInput $input): HookDecision {
            usleep(60000);

            return HookDecision::allow();
        });
        $this->expectExceptionMessage('extension_hook_failed');
        $hooks->posting(new PostingInput(1, 2, 'body'));
    }

    public function testStagedUpdateCannotReplaceActiveAndRollbackKeepsOldFiles(): void
    {
        $this->write();
        $this->files->write('extensions/acme/hello/src/Provider.php', '<?php return null;');
        $this->manager->enable('acme/hello', static fn() => null);
        $archive = new ExtensionArchive($this->manager);
        $zip = $this->zip([
            'extension.json' => self::json(['version' => '1.1.0']),
            'src/Provider.php' => '<?php return null;',
        ]);
        $token = $archive->stage($zip);
        try {
            $archive->install($token);
            self::fail('active update installed');
        } catch (ExtensionFailure $failure) {
            self::assertSame('extension_update_requires_disable', $failure->getMessage());
        }

        $this->manager->disable('acme/hello');
        self::assertSame([], $archive->previousVersions(), 'nothing kept before an update');
        $archive->install($token);
        self::assertSame('1.1.0', $this->manager->catalog->discover()['manifests']['acme/hello']->version);
        self::assertSame(['acme/hello' => ['token' => $token, 'version' => '1.0.0']], $archive->previousVersions());
        $archive->rollback($token);
        self::assertSame('1.0.0', $this->manager->catalog->discover()['manifests']['acme/hello']->version);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function upgrades(): iterable
    {
        yield 'metadata is not newer' => ['1.0.0+build.1', '1.0.0+build.2', false];
        yield 'prerelease downgrade' => ['1.0.0', '1.0.0-beta.1', false];
        yield 'stable after prerelease' => ['1.0.0-beta.1', '1.0.0', true];
        yield 'next prerelease' => ['1.0.0-beta.1', '1.0.0-beta.2', true];
        yield 'patch upgrade' => ['1.0.0', '1.0.1', true];
    }

    #[DataProvider('upgrades')]
    public function testUpgradeComparisonUsesSemverIncludingBuildMetadata(
        string $installed,
        string $candidate,
        bool $newer,
    ): void {
        $this->write(['version' => $installed]);
        if (!$newer) {
            $this->expectExceptionMessage('extension_update_not_newer');
        }

        $this->manager->assertCandidate(ExtensionManifest::parse(self::json(['version' => $candidate])));
        if ($newer) {
            self::assertSame($installed, $this->manager->catalog->discover()['manifests']['acme/hello']->version);
        }
    }

    public function testIncompatibleUpdateLeavesEnabledVersionAndFilesUntouched(): void
    {
        $this->write();
        $this->manager->enable('acme/hello', static fn() => null);
        $zip = $this->zip([
            'extension.json' => self::json(['version' => '2.0.0', 'engine' => '^2']),
            'src/p.php' => '<?php',
        ]);
        try {
            (new ExtensionArchive($this->manager))->stage($zip);
            self::fail('incompatible update staged');
        } catch (ExtensionFailure $failure) {
            self::assertSame('extension_incompatible', $failure->getMessage());
        }

        self::assertSame('1.0.0', $this->manager->active()[0]->version);
    }

    public function testArchiveTraversalIsRejected(): void
    {
        $zip = $this->zip(['extension.json' => self::json(), '../escape.php' => '<?php']);
        $archive = new ExtensionArchive($this->manager);

        $this->expectExceptionMessage('extension_path_invalid');
        $archive->stage($zip);
    }

    public function testStagedIdAndTamperedStageCannotInstall(): void
    {
        $zip = $this->zip(['extension.json' => self::json(), 'src/Provider.php' => '<?php return null;']);
        $archive = new ExtensionArchive($this->manager);
        $token = $archive->stage($zip);
        self::assertSame('acme/hello', $archive->stagedId($token));
        $this->files->write('private/stage-' . $token . '/src/Provider.php', '<?php changed();');
        $this->expectExceptionMessage('extension_integrity_changed');
        $archive->install($token);
    }

    /** 2026-11-19: leaving safe mode forgets the old boot failure, so the panel stops reporting it. */
    public function testLeavingSafeModeForgetsTheEarlierBootFailure(): void
    {
        $this->write();
        $this->manager->enable('acme/hello', static fn() => null);
        $this->manager->state->rememberFailure();
        $this->manager->state->setSafe(true);
        self::assertSame('extension_boot_failed', $this->manager->diagnostics()['previous_boot']);
        $this->manager->resume();
        self::assertFalse($this->manager->state->hasBootFailure());
        self::assertArrayNotHasKey('previous_boot', $this->manager->diagnostics());
        $this->manager->state->clearFailure();
        self::assertFalse($this->manager->state->hasBootFailure());
    }

    public function testSafeModeDoesNotExecuteFailingProvider(): void
    {
        $this->write(['providers' => ['src/Provider.php']]);
        $this->files->write('extensions/acme/hello/src/Provider.php', '<?php throw new Exception();');
        $this->manager->enable('acme/hello', static fn() => null);
        $this->manager->state->setSafe(true);
        $registry = new ExtensionRegistry();
        ExtensionBootstrap::register($this->manager, new ContainerBuilder(), $registry);
        self::assertSame([], $registry->routes);
    }

    /** @param array<string, mixed> $change */
    private function write(array $change = []): void
    {
        $this->files->write('extensions/acme/hello/extension.json', self::json($change));
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = $this->files->path . '/package.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }

    /** @param array<string, mixed> $change */
    private static function json(array $change = []): string
    {
        return json_encode(array_replace([
            'id' => 'acme/hello', 'name' => 'Hello', 'version' => '1.0.0', 'api' => 1,
            'engine' => '^1', 'php' => '^8.4', 'license' => 'MIT',
        ], $change), JSON_THROW_ON_ERROR);
    }
}
