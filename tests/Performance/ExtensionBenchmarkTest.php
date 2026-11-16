<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Extensions\BeforeHooks;
use Nolviko\Core\Extensions\ComposerVersionConstraint;
use Nolviko\Core\Extensions\ExtensionCatalog;
use Nolviko\Core\Extensions\ExtensionResolver;
use Nolviko\Core\Extensions\PostingInput;
use PHPUnit\Framework\TestCase;

/** Isolated no-SQL extension discovery, compatibility and no-provider hook baseline. */
final class ExtensionBenchmarkTest extends TestCase
{
    public function testExtensionCostsAreRecorded(): void
    {
        $catalog = new ExtensionCatalog(dirname(__DIR__) . '/Fixtures/extensions');
        $manifests = $catalog->discover()['manifests'];
        $resolver = new ExtensionResolver(new ComposerVersionConstraint(), '1.0.0', PHP_VERSION);
        $hooks = new BeforeHooks();
        $input = new PostingInput(1, 2, 'benchmark');
        $cases = [
            'extension_discovery' => BenchmarkHarness::measure($catalog->discover(...), 100, 10),
            'extension_resolver' => BenchmarkHarness::measure(
                static fn(): array => $resolver->resolve($manifests, ['acme/hello']),
                100,
                10,
            ),
            'extension_empty_hook' => BenchmarkHarness::measure(static fn(): string => $hooks->posting($input)),
        ];
        self::assertCount(1, $manifests);
        self::assertCount(1, $resolver->resolve($manifests, ['acme/hello']));
        self::assertSame('benchmark', $hooks->posting($input));
        fwrite(
            STDOUT,
            'extension_benchmark ' . json_encode(['cases' => $cases, 'sql' => 0], JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
