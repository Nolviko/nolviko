<?php

declare(strict_types=1);

/*
 * Second process for MigrationRunnerTest: takes the migration lease, prints "held", keeps it for argv[1]
 * seconds, then releases it. Database settings come from the same FORUM_TEST_DB_* variables.
 */

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\Migration\MigrationLease;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$connection = ConnectionFactory::create(new Config(
    getenv('FORUM_TEST_DB_DRIVER') ?: 'mysql',
    (string) getenv('FORUM_TEST_DB_HOST'),
    null,
    (string) getenv('FORUM_TEST_DB_NAME'),
    (string) getenv('FORUM_TEST_DB_USER'),
    new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
    new Secret(str_repeat('s', 32)),
));
$lease = new MigrationLease($connection, static fn(): DateTimeImmutable => new DateTimeImmutable('now'));
$lease->acquire();
fwrite(STDOUT, "held\n");
fflush(STDOUT);
usleep((int) ((float) ($argv[1] ?? 2) * 1_000_000));
$lease->release();
fwrite(STDOUT, "released\n");
