<?php

declare(strict_types=1);

/*
 * Fresh-process probe used by tools/verify-foundation.php: bootstrap time, heap peak and Linux RSS peak.
 */

$start = hrtime(true);
require dirname(__DIR__) . '/config/bootstrap.php';
$elapsedMilliseconds = (hrtime(true) - $start) / 1e6;

// VmHWM is Linux-only; report 0 instead of failing elsewhere.
$status = is_readable('/proc/self/status') ? file_get_contents('/proc/self/status') : false;
$residentKibibytes = 0;

if (is_string($status) && preg_match('/^VmHWM:\s+(\d+)\s+kB/m', $status, $match) === 1) {
    $residentKibibytes = (int) $match[1];
}

echo json_encode(
    [
        'elapsed_ms' => $elapsedMilliseconds,
        'heap_bytes' => memory_get_peak_usage(true),
        'rss_kib' => $residentKibibytes,
        'php' => PHP_VERSION,
        'included_files' => count(get_included_files()),
    ],
    JSON_THROW_ON_ERROR,
);
