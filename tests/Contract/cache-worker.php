<?php

declare(strict_types=1);

/*
 * Worker process for FileCacheConcurrencyTest. Usage: cache-worker.php <mode> <storage> <go-file> <iterations>
 *   write    - stores self-verifying values of alternating size under one key
 *   read     - reads that key and counts hits, misses and torn (inconsistent) values
 *   remember - remember() with a slow computation that appends a line to <storage>/computed
 * Waits until <go-file> exists so all workers start together. Prints one JSON line.
 */

use Nolviko\Core\Cache\CacheFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $mode, $storage, $go, $iterations] = $argv;
$cache = (new CacheFactory($storage))->namespace('concurrency');

while (!is_file($go)) {
    usleep(1000);
}

$result = ['hits' => 0, 'misses' => 0, 'torn' => 0, 'value' => null];

// Readers must observe a writer publication, not exhaust their entire loop before the writer is scheduled.
if ($mode === 'read') {
    $deadline = microtime(true) + 5;

    while ($cache->get('shared') === null) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('cache_writer_never_published');
        }

        usleep(1000);
    }
}

for ($i = 0; $i < (int) $iterations; ++$i) {
    if ($mode === 'write') {
        $body = str_repeat(chr(97 + getmypid() % 26), $i % 2 === 0 ? 64 : 256 * 1024);
        $cache->set('shared', ['body' => $body, 'hash' => hash('sha256', $body)]);
    } elseif ($mode === 'read') {
        $value = $cache->get('shared');

        if ($value === null) {
            ++$result['misses'];
        } elseif (is_array($value) && hash('sha256', (string) $value['body']) === $value['hash']) {
            ++$result['hits'];
        } else {
            ++$result['torn'];
        }
    } else {
        $result['value'] = $cache->remember('stampede', null, static function () use ($storage): string {
            file_put_contents($storage . '/computed', getmypid() . "\n", FILE_APPEND | LOCK_EX);
            usleep(300_000);

            return 'computed-by-' . getmypid();
        });
    }
}

echo json_encode($result), "\n";
