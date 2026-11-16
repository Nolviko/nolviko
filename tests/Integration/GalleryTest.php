<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Gallery\Domain\GalleryRejected;
use Nolviko\Modules\Gallery\Domain\GalleryRules;
use Nolviko\Modules\Gallery\Domain\GalleryViewer;
use Nolviko\Modules\Gallery\Infrastructure\Gallery;
use Nolviko\Modules\Gallery\Infrastructure\GalleryImages;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Media gallery (2026-11-08): album visibility, administrators see everything, optional approval and quota,
 * size and type limits, owners-only changes, and re-encoded files outside the public directory.
 */
final class GalleryTest extends TestCase
{
    private Connection $connection;

    private TemporaryDirectory $storage;

    private Gallery $gallery;

    private GalleryViewer $alice;

    private GalleryViewer $bob;

    private GalleryViewer $admin;

    private GalleryViewer $moderator;

    private GalleryViewer $guest;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD not available');
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
        $this->storage = new TemporaryDirectory();
        mkdir($this->storage->path . '/storage');
        mkdir($this->storage->path . '/public');
        $this->gallery = new Gallery(
            $this->connection,
            new GalleryImages(),
            $this->storage->path . '/storage',
            $this->storage->path . '/public',
        );
        $users = new DbalUserRepository($this->connection);
        $ids = [];

        foreach (['alice', 'bob', 'root', 'mod'] as $name) {
            $ids[$name] = $users->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;
        }

        $this->alice = new GalleryViewer($ids['alice']);
        $this->bob = new GalleryViewer($ids['bob']);
        $this->admin = new GalleryViewer($ids['root'], admin: true);
        $this->moderator = new GalleryViewer($ids['mod'], moderator: true);
        $this->guest = new GalleryViewer(null);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
            $this->storage->remove();
        }
    }

    public function testComposerImagesShareOnePublicAlbumPerMember(): void
    {
        $first = $this->gallery->attachAlbum($this->alice, 'Post images');
        self::assertSame($first, $this->gallery->attachAlbum($this->alice, 'Post images'), 'reused');
        self::assertNotSame($first, $this->gallery->attachAlbum($this->bob, 'Post images'), 'per member');
        $image = $this->gallery->upload($this->alice, $first, $this->png('inline.png'), '', new GalleryRules());
        self::assertNotNull($this->gallery->item($this->guest, $image), 'public: readable in posts');
        self::assertSame('gallery_denied', self::refused(fn() => $this->gallery->attachAlbum($this->guest, 'x')));
    }

    public function testVisibilityOwnersAndAdministrators(): void
    {
        $public = $this->gallery->saveAlbum($this->alice, null, 'Holiday', 'Sea', 'public');
        $members = $this->gallery->saveAlbum($this->alice, null, 'Club', '', 'members');
        $private = $this->gallery->saveAlbum($this->alice, null, 'Diary', '', 'private');
        $rules = new GalleryRules();
        $images = [];

        foreach ([$public, $members, $private] as $album) {
            $images[$album] = $this->gallery->upload($this->alice, $album, $this->png('photo.png'), '', $rules);
        }

        self::assertNotNull($this->gallery->item($this->guest, $images[$public]));
        self::assertNull($this->gallery->item($this->guest, $images[$members]), 'members only');
        self::assertNotNull($this->gallery->item($this->bob, $images[$members]));
        self::assertNull($this->gallery->item($this->bob, $images[$private]), 'private');
        self::assertNull($this->gallery->album($this->bob, $private));
        self::assertNotNull($this->gallery->item($this->admin, $images[$private]), 'administrators see everything');
        self::assertCount(1, $this->gallery->albums($this->guest));
        self::assertCount(3, $this->gallery->albums($this->admin));
        self::assertSame('photo', $this->gallery->item($this->alice, $images[$public])['title'] ?? null);

        self::assertSame('gallery_denied', self::refused(fn() => $this->gallery->upload(
            $this->bob,
            $public,
            $this->png('x.png'),
            '',
            $rules,
        )), 'only the owner adds images');
        self::assertSame('gallery_denied', self::refused(
            fn() => $this->gallery->deleteItem($this->bob, $images[$public]),
        ));
        self::assertSame($public, $this->gallery->deleteItem($this->admin, $images[$public]));
        self::assertNull($this->gallery->item($this->alice, $images[$public]));

        $file = $this->gallery->file($this->bob, $images[$members], true);
        self::assertSame('image/png', $file->headers->get('Content-Type'));
        self::assertStringStartsWith(
            $this->storage->path . '/storage/gallery/',
            str_replace('\\', '/', $file->getFile()->getPathname()),
        );
        self::assertSame('gallery_not_found', self::refused(
            fn() => $this->gallery->file($this->guest, $images[$members], false),
        ));

        $this->gallery->deleteAlbum($this->alice, $members);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM gallery_items WHERE album_id = ?',
            [$members],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'gallery.item_deleted'",
        ), 'administrator deletions are audited');
    }

    public function testLimitsQuotaAndApproval(): void
    {
        $album = $this->gallery->saveAlbum($this->alice, null, 'Album', '', 'public');
        self::assertSame('gallery_visibility_invalid', self::refused(
            fn() => $this->gallery->saveAlbum($this->alice, null, 'X', '', 'everyone'),
        ));
        self::assertSame('gallery_type_refused', self::refused(fn() => $this->gallery->upload(
            $this->alice,
            $album,
            $this->png('photo.png'),
            '',
            new GalleryRules(extensions: ['jpg']),
        )), 'extension not allowed');
        $fake = $this->storage->path . '/fake.png';
        file_put_contents($fake, '<?php echo 1;');
        self::assertSame('gallery_type_refused', self::refused(fn() => $this->gallery->upload(
            $this->alice,
            $album,
            new UploadedFile($fake, 'fake.png', null, null, true),
            '',
            new GalleryRules(),
        )), 'the bytes decide, not the name');
        self::assertSame('gallery_too_large', self::refused(fn() => $this->gallery->upload(
            $this->alice,
            $album,
            $this->png('big.png', 600),
            '',
            new GalleryRules(maxBytes: 100),
        )));

        $first = $this->gallery->upload($this->alice, $album, $this->png('a.png'), 'First', new GalleryRules());
        $used = $this->gallery->usage($this->alice->id ?? 0);
        self::assertGreaterThan(0, $used);
        self::assertSame('gallery_quota', self::refused(fn() => $this->gallery->upload(
            $this->alice,
            $album,
            $this->png('b.png'),
            '',
            new GalleryRules(quotaBytes: $used + 10),
        )));

        $pending = $this->gallery->upload(
            $this->alice,
            $album,
            $this->png('c.png'),
            '',
            new GalleryRules(approval: true),
        );
        self::assertSame('pending', $this->gallery->item($this->alice, $pending)['status'] ?? null);
        self::assertNull($this->gallery->item($this->bob, $pending), 'pending images stay with their owner');
        self::assertSame([$pending], array_map(
            static fn(array $row): int => (int) $row['id'],
            $this->gallery->items($this->moderator, null, 0, true),
        ));
        self::assertSame('gallery_denied', self::refused(fn() => $this->gallery->approve($this->bob, $pending)));
        $this->gallery->approve($this->moderator, $pending);
        self::assertNotNull($this->gallery->item($this->bob, $pending));
        self::assertSame('visible', $this->gallery->item($this->bob, $first)['status'] ?? null);
    }

    private function png(string $name, int $size = 40): UploadedFile
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 120, 200));
        $path = $this->storage->path . '/' . bin2hex(random_bytes(4)) . '-' . $name;
        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private static function refused(callable $action): string
    {
        try {
            $action();
        } catch (GalleryRejected $rejected) {
            return $rejected->diagnostic;
        }

        return 'accepted';
    }
}
