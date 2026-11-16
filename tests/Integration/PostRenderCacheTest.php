<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Posts\Domain\Post;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M03.13 render cache on MySQL 8.4 (ADR-012): rendered once and written back, re-rendered when the renderer
 * version differs, reset by edits, and never overwritten by a rendering of an older source.
 */
final class PostRenderCacheTest extends TestCase
{
    private Connection $connection;

    private DbalPostRepository $posts;

    private int $thread;

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
        $forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'F', 'f')->id;
        $this->thread = (new DbalThreadRepository($this->connection))
            ->create($forum, null, 'T', 't', new DateTimeImmutable('2026-11-01'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testRenderedOnceThenServedFromTheColumn(): void
    {
        $source = '[b]x[/b] [spoiler]s[/spoiler]';
        $id = $this->posts->add($this->thread, null, $source, 'bbcode', new DateTimeImmutable());
        $renderer = new PostRenderer($this->posts);

        $first = $renderer->html($this->post($id), 'Spoiler');
        $stored = $this->connection->fetchAssociative(
            'SELECT rendered_html, renderer_version FROM posts WHERE id = ?',
            [$id],
        );
        $this->connection->update('posts', ['rendered_html' => '<em>cached</em>'], ['id' => $id]);
        $second = $renderer->html($this->post($id), 'Gizli');

        self::assertSame(
            '<strong>x</strong> <details class="bb-spoiler"><summary>Spoiler</summary>s</details>',
            $first->html,
        );
        self::assertSame(BbcodeRenderer::VERSION, (int) ($stored['renderer_version'] ?? 0));
        self::assertStringContainsString(BbcodeRenderer::SPOILER_LABEL, (string) ($stored['rendered_html'] ?? ''));
        self::assertSame('<em>cached</em>', $second->html, 'the current version is read from the column');
    }

    public function testOtherRendererVersionsAndEditsRenderAgain(): void
    {
        $id = $this->posts->add($this->thread, null, '[i]old[/i]', 'bbcode', new DateTimeImmutable());
        $renderer = new PostRenderer($this->posts);
        $renderer->html($this->post($id), 'S');
        $this->connection->update('posts', ['renderer_version' => BbcodeRenderer::VERSION - 1], ['id' => $id]);

        self::assertSame('<em>old</em>', $renderer->html($this->post($id), 'S')->html);

        $this->posts->edit($id, null, '[b]new[/b]', 'bbcode', null, new DateTimeImmutable());

        self::assertNull($this->post($id)->renderedHtml, 'an edit clears the cache');
        self::assertSame('<strong>new</strong>', $renderer->html($this->post($id), 'S')->html);
    }

    public function testAStaleRenderingNeverOverwritesANewerEdit(): void
    {
        $id = $this->posts->add($this->thread, null, 'v1', 'bbcode', new DateTimeImmutable());
        $before = $this->post($id);
        $this->posts->edit($id, null, 'v2', 'bbcode', null, new DateTimeImmutable());

        (new PostRenderer($this->posts))->html($before, 'S');

        self::assertNull($this->post($id)->renderedHtml, 'the rendering of v1 was not stored over v2');
    }

    private function post(int $id): Post
    {
        $post = $this->posts->find($id);
        self::assertNotNull($post);

        return $post;
    }
}
