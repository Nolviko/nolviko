<?php

declare(strict_types=1);

/*
 * Worker for CreateReplyTest: waits for <go-file>, then replies once as a member of the registered group.
 * Prints `created:<post id>`, `repeated:<post id>` or `violation:<diagnostic>`.
 * Usage: reply-worker.php <go-file> <cache-dir> <thread id> <user id> <token> [client ip]
 * Connection settings come from the FORUM_TEST_DB_* environment variables.
 */

use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\DbalReplyWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\PostingLock;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $go, $cacheDirectory, $threadId, $userId, $token] = $argv;
$clientIp = $argv[6] ?? null;
$connection = ConnectionFactory::create(new Config(
    getenv('FORUM_TEST_DB_DRIVER') ?: 'mysql',
    (string) getenv('FORUM_TEST_DB_HOST'),
    null,
    (string) getenv('FORUM_TEST_DB_NAME'),
    (string) getenv('FORUM_TEST_DB_USER'),
    new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
    new Secret(str_repeat('s', 32)),
));
$catalog = PermissionCatalog::core();
$counter = new VersionCounter($connection);
$posts = new DbalPostRepository($connection);
$replies = new CreateReply(
    new DbalThreadRepository($connection),
    new ForumVisibility(
        (new CacheFactory($cacheDirectory))->namespace('permissions'),
        static fn(): int => $counter->current(VersionCounter::PERMISSION_RULES),
    ),
    $posts,
    new DbalReplyWriter($connection, new Outbox($connection), new PostingLock($connection)),
);
// Resolve permissions and connect before the race starts, so only the writes compete.
$subject = (new PermissionResolver($catalog, new DbalPermissionRuleStore($connection, $catalog), new DbalForumTree(
    $connection,
)))->user((int) $userId, [BuiltInGroup::Registered->value]);
$replies->authorize($subject, (int) $threadId);

while (!is_file($go)) {
    usleep(500);
}

try {
    $reply = $replies->reply($subject, (int) $threadId, $token, 'Reply from user ' . $userId, $clientIp);
    echo $reply->repeated ? 'repeated:' : 'created:', $reply->postId, "\n";
} catch (ThreadRuleViolation $violation) {
    echo 'violation:', $violation->diagnostic, "\n";
}
