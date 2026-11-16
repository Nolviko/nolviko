<?php

declare(strict_types=1);

/*
 * Worker for UserRepositoryTest: waits for <go-file>, then creates one user. Prints `created:<id>` or
 * `taken:<field>`. Usage: register-worker.php <go-file> <username> <email>
 * Connection settings come from the FORUM_TEST_DB_* environment variables.
 */

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Users\Domain\IdentityTaken;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $go, $username, $email] = $argv;
$repository = new DbalUserRepository(ConnectionFactory::create(new Config(
    getenv('FORUM_TEST_DB_DRIVER') ?: 'mysql',
    (string) getenv('FORUM_TEST_DB_HOST'),
    null,
    (string) getenv('FORUM_TEST_DB_NAME'),
    (string) getenv('FORUM_TEST_DB_USER'),
    new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
    new Secret(str_repeat('s', 32)),
)));
$repository->findById(0); // connect before the race starts

while (!is_file($go)) {
    usleep(500);
}

try {
    $id = $repository->create($username, $email, 'hash', UserStatus::PendingVerification)->id;
    echo 'created:', $id, "\n";
} catch (IdentityTaken $taken) {
    echo 'taken:', $taken->field, "\n";
}
