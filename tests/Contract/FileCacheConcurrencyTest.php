<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * M01.10 with real parallel PHP processes on the local filesystem: no torn reads while values are replaced,
 * and a cold key is computed once while other processes wait (stampede protection).
 */
final class FileCacheConcurrencyTest extends TestCase
{
    private TemporaryDirectory $storage;

    protected function setUp(): void
    {
        $this->storage = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->storage->remove();
    }

    public function testParallelWritersNeverProduceATornRead(): void
    {
        $results = $this->runWorkers([['write', 300], ['write', 300], ['read', 1500], ['read', 1500]]);
        $reads = array_slice($results, 2);

        self::assertSame(0, array_sum(array_column($reads, 'torn')));
        self::assertGreaterThan(0, array_sum(array_column($reads, 'hits')));
        self::assertSame([], glob($this->storage->path . '/cache/data/concurrency/*/*.tmp') ?: []);
    }

    public function testColdKeyIsComputedOnceUnderContention(): void
    {
        $results = $this->runWorkers(array_fill(0, 5, ['remember', 1]));
        $values = array_unique(array_column($results, 'value'));

        self::assertCount(1, file($this->storage->path . '/computed') ?: []);
        self::assertCount(1, $values);
        self::assertStringStartsWith('computed-by-', (string) current($values));
    }

    /**
     * @param list<array{0: string, 1: int}> $workers Mode and iterations per process.
     *
     * @return list<array{hits: int, misses: int, torn: int, value: string|null}>
     */
    private function runWorkers(array $workers): array
    {
        $go = $this->storage->path . '/go';
        $processes = [];

        foreach ($workers as [$mode, $iterations]) {
            $command = [PHP_BINARY, __DIR__ . '/cache-worker.php', $mode, $this->storage->path, $go];
            $command[] = (string) $iterations;
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        touch($go);
        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
