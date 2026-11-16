<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\PostingRules;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\ContentPolicy;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Infrastructure\DbalModerationWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalPostEditor;
use Nolviko\Modules\Threads\Infrastructure\DbalReplyWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadWriter;
use Nolviko\Modules\Threads\Infrastructure\PostingLock;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M03.15a on MySQL 8.4: the first N posts of a user wait for moderator approval, new users may add only a few
 * links, blocked words and link domains refuse a post (also when editing), links are `nofollow ugc`, and
 * moderators are exempt.
 */
final class AntiSpamTest extends TestCase
{
    private Connection $connection;

    private DbalPostRepository $posts;

    private DbalThreadRepository $threads;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    /** @var array<string, int> */
    private array $users = [];

    private int $forum;

    private int $thread;

    private DateTimeImmutable $now;

    private int $minute = 0;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ));
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->posts = new DbalPostRepository($this->connection);
        $this->threads = new DbalThreadRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        // Registered users post without a flood interval here; the limits are M03.15's subject.
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, CreateThread::FLOOD, 0);
        $accounts = new DbalUserRepository($this->connection);

        foreach (['newbie', 'veteran', 'mod'] as $name) {
            $this->users[$name] = $accounts->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;
        }

        // The real clock: account age is measured against users.created_at.
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->connection->update(
            'users',
            ['created_at' => $this->now->modify('-30 days')->format('Y-m-d H:i:s.u')],
            ['id' => $this->users['veteran']],
        );
        $this->forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'F', 'f')->id;
        $this->thread = $this->threadService(new PostingRules())
            ->create($this->as('veteran'), $this->forum, 'Topic', 'First post')->threadId;

        foreach (range(1, 3) as $i) {
            $this->reply('veteran', 'Veteran ' . $i, new PostingRules());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testTheFirstPostsWaitUntilAModeratorApproves(): void
    {
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.approval', 'first_posts');
        $rules = new PostingRules(firstPostsModerated: 2);
        $first = $this->reply('newbie', 'One', $rules);
        $second = $this->reply('newbie', 'Two', $rules);

        self::assertSame(
            [PostStatus::Pending, PostStatus::Pending],
            [$this->postStatus($first), $this->postStatus($second)],
        );
        self::assertSame(3, $this->replyCount(), 'pending replies are not counted');
        self::assertSame('post_not_found', $this->moderationViolation('veteran', $first), 'hidden from members');

        $this->moderation()->post($this->as('mod'), $first, ModerateContent::APPROVE, 'welcome');

        self::assertSame(PostStatus::Visible, $this->postStatus($first));
        self::assertSame(4, $this->replyCount());
        self::assertSame('content_not_pending', $this->moderationViolation('mod', $first));
        $third = $this->reply('newbie', 'Three', $rules);

        self::assertSame(PostStatus::Pending, $this->postStatus($third), '1 of 2 visible');

        $this->moderation()->post($this->as('mod'), $second, ModerateContent::APPROVE);

        $fourth = $this->reply('newbie', 'Four', $rules);

        self::assertSame(PostStatus::Visible, $this->postStatus($fourth), '2 visible: free');
        self::assertSame(
            ['post.approve', 'post.approve'],
            $this->connection->fetchFirstColumn("SELECT action FROM audit_logs WHERE action = 'post.approve'"),
        );
    }

    public function testApprovingAPendingThreadPublishesItsFirstPostAndCountsIt(): void
    {
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.approval', 'all');
        $created = $this->threadService(new PostingRules())->create($this->as('newbie'), $this->forum, 'New', 'Body');
        $before = $this->forumCounts();

        self::assertSame(ThreadStatus::Pending, $created->status);

        $this->moderation()->thread($this->as('mod'), $created->threadId, ModerateContent::APPROVE);

        self::assertSame(ThreadStatus::Visible, $this->threads->find($created->threadId)?->status);
        self::assertSame(PostStatus::Visible, $this->postStatus($created->postId));
        self::assertSame([$before[0] + 1, $before[1] + 1], $this->forumCounts());
    }

    public function testNewUsersMayAddOnlyAFewLinks(): void
    {
        $rules = new PostingRules(newUserMaxLinks: 1);
        $two = '[url=https://example.com/a]a[/url] https://example.com/b';

        self::assertSame(['post_links_limited', ['max' => 1]], $this->violation('newbie', $two, $rules));
        self::assertNull($this->violation('newbie', 'Only https://example.com/c here', $rules));
        self::assertSame(
            'post_links_limited',
            $this->violation('newbie', '[img]https://example.com/x.png[/img] https://e.com', $rules)[0] ?? null,
            'images count as links',
        );
        self::assertNull($this->violation('veteran', $two . ' https://example.com/c', $rules), 'old, 4 posts');
        self::assertNull($this->violation('mod', $two, $rules), 'moderators are exempt');

        // An old account with too few posts is still new.
        $this->connection->update(
            'users',
            ['created_at' => $this->now->modify('-30 days')->format('Y-m-d H:i:s.u')],
            ['id' => $this->users['newbie']],
        );
        self::assertSame('post_links_limited', $this->violation('newbie', $two, $rules)[0] ?? null);
    }

    public function testBlockedWordsAndDomainsAlsoOnEdit(): void
    {
        $rules = new PostingRules(blockedWords: ['casino', 'Çok kötü'], blockedDomains: ['spam.example']);

        self::assertSame('post_content_blocked', $this->violation('veteran', 'Best CASINO bonus', $rules)[0] ?? null);
        self::assertSame('post_content_blocked', $this->violation('veteran', 'Bu ÇOK KÖTÜ', $rules)[0] ?? null);
        self::assertNull($this->violation('veteran', 'casinos and casinoX are other words', $rules));
        self::assertSame(
            'post_link_blocked',
            $this->violation('veteran', '[url=https://www.spam.example/x]x[/url]', $rules)[0] ?? null,
        );
        self::assertNull($this->violation('veteran', 'https://notspam.example/ is fine', $rules));
        self::assertNull($this->violation('mod', 'casino at https://spam.example', $rules));

        try {
            $this->threadService($rules)->create($this->as('veteran'), $this->forum, 'Casino night', 'Body');
            self::fail('a blocked word in the title was accepted');
        } catch (ThreadRuleViolation $violation) {
            self::assertSame('post_content_blocked', $violation->diagnostic);
        }

        $post = $this->reply('veteran', 'Clean', new PostingRules());

        try {
            $this->editService($rules)->edit($this->as('veteran'), $post, 'Now with https://spam.example/');
            self::fail('an edit added a blocked link');
        } catch (ThreadRuleViolation $violation) {
            self::assertSame('post_link_blocked', $violation->diagnostic);
        }
    }

    public function testUserLinksAreNofollowUgc(): void
    {
        $post = $this->reply('veteran', '[url=https://example.com]x[/url] and https://example.org', new PostingRules());
        $html = (string) $this->posts->find($post)?->renderedHtml;

        self::assertSame(2, substr_count($html, 'rel="nofollow ugc noopener"'));
    }

    /**
     * @return array{string, array<string, int|string>}|null Diagnostic and parameters, or null when accepted.
     */
    private function violation(string $user, string $content, PostingRules $rules): ?array
    {
        try {
            $this->reply($user, $content, $rules);
        } catch (ThreadRuleViolation $violation) {
            return [$violation->diagnostic, $violation->parameters];
        }

        return null;
    }

    private function moderationViolation(string $user, int $postId): string
    {
        try {
            $this->moderation()->post($this->as($user), $postId, ModerateContent::APPROVE);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    private function reply(string $user, string $content, PostingRules $rules): int
    {
        $at = $this->at();

        return (new CreateReply(
            $this->threads,
            $this->visibility(),
            $this->posts,
            new DbalReplyWriter($this->connection, new Outbox($this->connection), new PostingLock($this->connection)),
            clock: static fn(): DateTimeImmutable => $at,
            policy: $this->policy($rules),
        ))->reply($this->as($user), $this->thread, CreateReply::newToken(), $content)->postId;
    }

    private function threadService(PostingRules $rules): CreateThread
    {
        $at = $this->at();

        return new CreateThread(
            new DbalForumRepository($this->connection),
            $this->visibility(),
            $this->posts,
            new DbalThreadWriter($this->connection, $this->threads, new Outbox($this->connection), new PostingLock(
                $this->connection,
            )),
            clock: static fn(): DateTimeImmutable => $at,
            policy: $this->policy($rules),
        );
    }

    private function editService(PostingRules $rules): EditPost
    {
        $at = $this->at();

        return new EditPost(
            $this->posts,
            $this->threads,
            $this->visibility(),
            new DbalPostEditor($this->connection, $this->posts, new Outbox($this->connection)),
            clock: static fn(): DateTimeImmutable => $at,
            policy: $this->policy($rules),
        );
    }

    private function moderation(): ModerateContent
    {
        return new ModerateContent(
            $this->posts,
            $this->threads,
            $this->visibility(),
            new DbalModerationWriter($this->connection, new Outbox($this->connection)),
        );
    }

    private function policy(PostingRules $rules): ContentPolicy
    {
        return new ContentPolicy($rules, $this->posts, new DbalUserRepository($this->connection));
    }

    private function postStatus(int $postId): ?PostStatus
    {
        return $this->posts->find($postId)?->status;
    }

    private function replyCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT reply_count FROM threads WHERE id = ?', [$this->thread]);
    }

    /**
     * @return array{int, int}
     */
    private function forumCounts(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT thread_count, post_count FROM forum_statistics WHERE forum_id = ?',
            [$this->forum],
        );
        self::assertIsArray($row);

        return [(int) $row['thread_count'], (int) $row['post_count']];
    }

    private function as(string $user): SubjectPermissions
    {
        $groups = [BuiltInGroup::Registered->value];

        if ($user === 'mod') {
            $groups[] = BuiltInGroup::Moderators->value;
        }

        return (new PermissionResolver(
            PermissionCatalog::core(),
            $this->rules,
            new DbalForumTree($this->connection),
        ))->user($this->users[$user], $groups);
    }

    /**
     * Post times a minute apart (within the edit window of the real-clock accounts).
     */
    private function at(): DateTimeImmutable
    {
        return $this->now->modify('+' . (++$this->minute) . ' seconds');
    }

    private function visibility(): ForumVisibility
    {
        $cache = (new CacheFactory($this->storage->path))->namespace('permissions');
        $counter = new VersionCounter($this->connection);

        return new ForumVisibility($cache, static fn(): int => $counter->current(VersionCounter::PERMISSION_RULES));
    }
}
