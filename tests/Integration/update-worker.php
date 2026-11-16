<?php

declare(strict_types=1);

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\UpdateJournal;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $go, $action, $id, $stamp] = $argv;
$journal = new UpdateJournal(ConnectionFactory::create(new Config(
    (string) (getenv('FORUM_TEST_DB_DRIVER') ?: 'mysql'),
    (string) getenv('FORUM_TEST_DB_HOST'),
    null,
    (string) getenv('FORUM_TEST_DB_NAME'),
    (string) getenv('FORUM_TEST_DB_USER'),
    new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
    new Secret(str_repeat('s', 32)),
)));
$now = new DateTimeImmutable($stamp);

while (!is_file($go)) {
    usleep(500);
}

try {
    if ($action === 'begin') {
        $journal->begin('release-2', str_repeat('a', 64), $now);
    } else {
        $journal->claim($id, $now);
    }

    echo 'accepted';
} catch (RuntimeException $error) {
    if (!in_array($error->getMessage(), ['update_already_active', 'update_claim_refused', 'update_begin_busy'], true)) {
        throw $error;
    }

    echo 'refused';
}
