<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Application;

use Nolviko\Core\Application\VisitorTasks;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/** 2026-11-17: Admin > Background tasks shows when visits last ran tasks (storage/tasks/tick). */
final class VisitorTasksTest extends TestCase
{
    public function testLastRunReadsTheTickFile(): void
    {
        $storage = new TemporaryDirectory();

        try {
            self::assertNull(VisitorTasks::lastRun($storage->path), 'no tick yet');

            $storage->write('tasks/tick', '1794923773');
            self::assertSame(1794923773, VisitorTasks::lastRun($storage->path));

            $storage->write('tasks/tick', 'garbage');
            self::assertNull(VisitorTasks::lastRun($storage->path), 'only digits count');
        } finally {
            $storage->remove();
        }
    }

    public function testTicksAreFrequentButBounded(): void
    {
        self::assertGreaterThanOrEqual(10, VisitorTasks::INTERVAL_SECONDS);
        self::assertLessThanOrEqual(60, VisitorTasks::INTERVAL_SECONDS);
    }
}
