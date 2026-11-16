<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\ThemeAssetBuilder;
use Nolviko\Core\View\ThemeTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TrackingCache;
use PHPUnit\Framework\TestCase;

/** Real shipped layout: cold construction, warm rendering, warm guest HTML and emitted asset bytes. */
final class ThemeBenchmarkTest extends TestCase
{
    public function testThemeRenderAndPhpAssetBuildBudgets(): void
    {
        $app = dirname(__DIR__, 2);
        $files = new TemporaryDirectory();
        try {
            $translator = TranslatorFactory::create($app . '/translations', 'en');
            $context = ['title' => 'Theme benchmark', 'forums' => []];
            $raw = TwigFactory::create($app, $files->path, $translator, new CspNonce('benchmark-current-nonce'));
            $identity = static fn(): array => ['user' => null, 'policy' => 'guest', 'locale' => 'en',
                'mode' => 'system', 'theme' => 'default', 'preview' => false, 'method' => 'GET'];
            $cache = new TrackingCache((new CacheFactory($files->path))->namespace('bench-theme'));
            $cached = TwigFactory::create(
                $app,
                $files->path,
                $translator,
                new CspNonce('benchmark-current-nonce'),
                renderCache: $cache,
                renderIdentity: $identity
            );
            self::assertSame(
                $raw->render(
                    'forums/index.twig',
                    $context
                ),
                $cached->render(
                    'forums/index.twig',
                    $context
                )
            );
            $results = [
                'theme_warm_raw' => BenchmarkHarness::measure(static fn(): string => $raw->render(
                    'forums/index.twig',
                    $context
                )),
                'theme_warm_guest_cache' => BenchmarkHarness::measure(
                    static fn(): string => $cached->render(
                        'forums/index.twig',
                        $context
                    )
                ),
                'theme_factory_no_disk_cache' => BenchmarkHarness::measure(static fn(): string => TwigFactory::create(
                    $app,
                    $files->path . '/absent',
                    $translator,
                    new CspNonce('benchmark-current-nonce'),
                )->render('forums/index.twig', $context)),
            ];
            self::assertGreaterThan(0, $cache->hits, 'the measured warm cache must actually hit');
            $results['theme_fresh_process_cold'] = self::cold();
            $source = (string) file_get_contents($app . '/public/assets/theme.css');
            $bytes = ['source_css' => strlen($source),
                'built_css' => strlen(ThemeAssetBuilder::build(
                    'theme.css',
                    $source
                )),
                'token_css' => strlen(ThemeTokens::css([])), 'new_js' => 0, 'new_dependencies' => 0];
            self::assertLessThan($bytes['source_css'], $bytes['built_css']);
            self::assertLessThan(4096, $bytes['token_css']);
            fwrite(
                STDERR,
                'theme_benchmark ' . json_encode(
                    ['cases' => $results,
                    'bytes' => $bytes],
                    JSON_THROW_ON_ERROR
                ) . "\n"
            );
        } finally {
            $files->remove();
        }
    }
    private static function cold(): array
    {
        $samples = [];
        $peak = 0;
        for ($index = 0; $index < 5; ++$index) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/theme-cold-worker.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['hash']);
            $samples[] = $result['milliseconds'];
            $peak = max($peak, $result['peak']);
        }
        sort($samples);
        return ['samples' => 5, 'p50_ms' => $samples[2], 'p95_ms' => $samples[4], 'max_peak_bytes' => $peak];
    }
}
