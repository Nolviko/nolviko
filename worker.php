<?php

declare(strict_types=1);

use Nolviko\Core\Application\TaskCli;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/config/bootstrap.php';
exit(TaskCli::run(__DIR__, getenv(), array_slice($argv, 1)));
