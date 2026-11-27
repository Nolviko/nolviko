<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Core;

use Nolviko\Core\Runtime\DirectoryMove;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11-27: extension and theme installs move folders with one helper (rename, or a copy across disks).
 */
final class DirectoryMoveTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nolviko-move-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/source/views', 0700, true);
        file_put_contents($this->root . '/source/theme.json', '{}');
        file_put_contents($this->root . '/source/views/index.twig', 'x');
    }

    protected function tearDown(): void
    {
        DirectoryMove::removeTree($this->root);
    }

    public function testMovesATree(): void
    {
        self::assertTrue(DirectoryMove::move($this->root . '/source', $this->root . '/target'));
        self::assertFileExists($this->root . '/target/views/index.twig');
        self::assertDirectoryDoesNotExist($this->root . '/source');
    }

    public function testRemoveTreeRemovesEverything(): void
    {
        DirectoryMove::removeTree($this->root . '/source');

        self::assertDirectoryDoesNotExist($this->root . '/source');
        DirectoryMove::removeTree($this->root . '/missing');
        self::assertDirectoryExists($this->root);
    }

    public function testAMissingSourceIsNotMoved(): void
    {
        self::assertFalse(DirectoryMove::move($this->root . '/missing', $this->root . '/target'));
        self::assertDirectoryDoesNotExist($this->root . '/target');
    }
}
