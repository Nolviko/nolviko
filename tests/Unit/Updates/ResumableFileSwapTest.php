<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Updates\FileSwitchPlan;
use Nolviko\Core\Updates\ResumableFileSwap;
use Nolviko\Tests\Support\FileSwitchFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResumableFileSwapTest extends TestCase
{
    private const JOB = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FileSwitchFixture $fixture;

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->root->remove();
        }
    }

    public function testBoundedStepsRestartAndPreservePrivateAndCustomFiles(): void
    {
        $this->fixture = new FileSwitchFixture();
        $plan = $this->fixture->plan();
        $cursor = 0;

        do {
            $progress = $this->runner()->step($plan, self::JOB, static function (): void {
            }, 1);
            self::assertSame(++$cursor, $progress->nextIndex);
        } while (!$progress->complete);

        self::assertSame(3, $cursor);
        self::assertSame('new-a', file_get_contents($this->fixture->live . '/public/a.php'));
        self::assertSame('new-vendor', file_get_contents($this->fixture->live . '/vendor/autoload.php'));
        self::assertFileDoesNotExist($this->fixture->live . '/public/obsolete.php');
        self::assertSame('private-config', file_get_contents($this->fixture->live . '/config/local.php'));
        self::assertSame('installed', file_get_contents($this->fixture->live . '/config/install.lock'));
        self::assertSame('member-data', file_get_contents($this->fixture->live . '/storage/uploads/member.bin'));
        self::assertSame('user-theme', file_get_contents($this->fixture->live . '/themes/custom/user.css'));
        self::assertFileExists($this->fixture->live . '/config/maintenance.lock');
        $again = $this->runner()->step($plan, self::JOB, static function (): void {
        });
        self::assertTrue($again->complete);
        self::assertSame($cursor, $again->nextIndex);
    }

    public function testKilledAfterRenameBeforeCheckpointReplaysWithoutDamage(): void
    {
        $this->fixture = new FileSwitchFixture();
        $plan = $this->fixture->plan();
        $calls = 0;

        try {
            $this->runner()->step($plan, self::JOB, static function () use (&$calls): void {
                if (++$calls === 4) {
                    throw new RuntimeException('fixture_killed_after_rename');
                }
            }, 1);
            self::fail('Expected interruption');
        } catch (RuntimeException $failure) {
            self::assertSame('fixture_killed_after_rename', $failure->getMessage());
        }

        self::assertSame('new-a', file_get_contents($this->fixture->live . '/public/a.php'));
        $state = json_decode(file_get_contents($this->state()), true);
        self::assertSame(0, $state['next']);
        self::assertTrue($this->runner()->step($plan, self::JOB, static function (): void {
        })->complete);
    }

    public function testLeaseLostBeforeRenameLeavesOldFileUntouched(): void
    {
        $this->fixture = new FileSwitchFixture();
        $calls = 0;

        try {
            $this->runner()->step($this->fixture->plan(), self::JOB, static function () use (&$calls): void {
                if (++$calls === 3) {
                    throw new RuntimeException('fixture_lease_lost');
                }
            });
            self::fail('Expected guard failure');
        } catch (RuntimeException $failure) {
            self::assertSame('fixture_lease_lost', $failure->getMessage());
        }

        self::assertSame('old-a', file_get_contents($this->fixture->live . '/public/a.php'));
        self::assertFileExists($this->fixture->live . '/config/maintenance.lock');
    }

    public function testMissingCheckpointDoesNotGuessProgress(): void
    {
        $this->fixture = new FileSwitchFixture();
        $plan = $this->fixture->plan();
        $this->runner()->step($plan, self::JOB, static function (): void {
        }, 1);
        unlink($this->state());
        $this->expectExceptionMessage('update_switch_checkpoint_missing');
        $this->runner()->step($plan, self::JOB, static function (): void {
        });
    }

    public function testModifiedCompletedPrefixIsRejected(): void
    {
        $this->fixture = new FileSwitchFixture();
        $plan = $this->fixture->plan();
        $this->runner()->step($plan, self::JOB, static function (): void {
        }, 1);
        file_put_contents($this->fixture->live . '/public/a.php', 'changed');
        $this->expectExceptionMessage('update_switch_completed_file_changed');
        $this->runner()->step($plan, self::JOB, static function (): void {
        });
    }

    public function testLocalModificationIsDetectedBeforeAnyPromotion(): void
    {
        $this->fixture = new FileSwitchFixture();
        file_put_contents($this->fixture->live . '/public/obsolete.php', 'local edit');

        try {
            $this->runner()->step($this->fixture->plan(), self::JOB, static function (): void {
            });
            self::fail('Expected local change detection');
        } catch (RuntimeException $failure) {
            self::assertSame('update_switch_local_change', $failure->getMessage());
        }

        self::assertSame('old-a', file_get_contents($this->fixture->live . '/public/a.php'));
    }

    public function testStagedTamperingAfterPlanCannotChangeLiveFile(): void
    {
        $this->fixture = new FileSwitchFixture();
        $plan = $this->fixture->plan();
        file_put_contents($this->fixture->stage . '/public/a.php', 'wrong');

        try {
            $this->runner()->step($plan, self::JOB, static function (): void {
            });
            self::fail('Expected hash rejection');
        } catch (RuntimeException $failure) {
            self::assertSame('update_switch_stage_changed', $failure->getMessage());
        }

        self::assertSame('old-a', file_get_contents($this->fixture->live . '/public/a.php'));
    }

    public function testStagedTamperingBeforePlanIsRejected(): void
    {
        $this->fixture = new FileSwitchFixture();
        file_put_contents($this->fixture->stage . '/public/a.php', 'wrong');
        $this->expectExceptionMessage('update_switch_stage_changed');
        $this->fixture->plan();
    }

    public function testMaintenanceMustRemainPresent(): void
    {
        $this->fixture = new FileSwitchFixture();
        unlink($this->fixture->live . '/config/maintenance.lock');
        $this->expectExceptionMessage('update_switch_maintenance_required');
        $this->runner()->step($this->fixture->plan(), self::JOB, static function (): void {
        });
    }

    public function testACompetingStepCannotAcquireTheFilesystemLock(): void
    {
        $this->fixture = new FileSwitchFixture();
        $directory = $this->fixture->private . '/switch-' . self::JOB;
        mkdir($directory, 0700);
        $lock = fopen($directory . '/lock', 'c+b');
        flock($lock, LOCK_EX | LOCK_NB);

        try {
            $this->expectExceptionMessage('update_switch_busy');
            $this->runner()->step($this->fixture->plan(), self::JOB, static function (): void {
            });
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testSourceOrTargetLinksAreRefused(): void
    {
        $this->fixture = new FileSwitchFixture();
        $plan = $this->fixture->plan();
        unlink($this->fixture->live . '/public/a.php');
        symlink($this->fixture->live . '/config/local.php', $this->fixture->live . '/public/a.php');
        $this->expectExceptionMessage('update_switch_link_or_parent_invalid');
        $this->runner()->step($plan, self::JOB, static function (): void {
        });
    }

    public function testControlCodeInsideLiveApplicationIsRefused(): void
    {
        $this->fixture = new FileSwitchFixture();
        $this->expectExceptionMessage('update_switch_control_not_isolated');
        new ResumableFileSwap(dirname(__DIR__, 3), $this->fixture->private);
    }

    public function testOneLargeFileCannotCauseAnUnboundedStep(): void
    {
        $this->fixture = new FileSwitchFixture([], [
            'public/a.bin' => str_repeat('x', 5242880),
            'public/z.bin' => 'last',
        ]);
        $progress = $this->runner()->step($this->fixture->plan(), self::JOB, static function (): void {
        });
        self::assertSame(1, $progress->nextIndex);
        self::assertFalse($progress->complete);
        self::assertFileDoesNotExist($this->fixture->live . '/public/z.bin');
    }

    #[DataProvider('protectedPaths')]
    public function testPrivatePathsCannotEnterAReleasePlan(string $path): void
    {
        $this->expectException(RuntimeException::class);
        FileSwitchPlan::path($path);
    }

    /**
     * @return list<array{string}>
     */
    public static function protectedPaths(): array
    {
        return array_map(static fn(string $path): array => [$path], [
            '.env', '.env.local', '.env/child', 'config/local.php', 'config/local.php/child',
            'config/install.lock', 'config/maintenance.lock', 'storage', 'storage/uploads/a',
            'storage/backups/a', 'public/update-recovery.php', '../outside', '/absolute',
        ]);
    }

    public function testUnknownNewPathCannotOverwriteACustomFile(): void
    {
        $this->fixture = new FileSwitchFixture([], ['public/custom.php' => 'publisher-file']);
        $this->fixture->root->write('live/public/custom.php', 'user-file');
        $this->expectExceptionMessage('update_switch_local_change');
        $this->runner()->step($this->fixture->plan(), self::JOB, static function (): void {
        });
    }

    public function testChangedPlanCannotReuseAStartedJob(): void
    {
        $this->fixture = new FileSwitchFixture();
        $this->runner()->step($this->fixture->plan(), self::JOB, static function (): void {
        }, 1);
        $other = FileSwitchPlan::prepare(
            $this->fixture->archive,
            $this->fixture->manifest,
            $this->fixture->stage,
            [],
        );
        $this->expectExceptionMessage('update_switch_state_identity');
        $this->runner()->step($other, self::JOB, static function (): void {
        });
    }

    public function testCorruptCheckpointIsRejected(): void
    {
        $this->fixture = new FileSwitchFixture();
        $plan = $this->fixture->plan();
        $this->runner()->step($plan, self::JOB, static function (): void {
        }, 1);
        file_put_contents($this->state(), '{');
        $this->expectExceptionMessage('update_switch_state_invalid');
        $this->runner()->step($plan, self::JOB, static function (): void {
        });
    }

    public function testCaseAliasingAcrossReleasesIsRejected(): void
    {
        $this->fixture = new FileSwitchFixture(['public/a.php' => 'old'], ['public/A.php' => 'new']);
        $this->expectExceptionMessage('update_switch_path_collision');
        $this->fixture->plan();
    }

    public function testFileDirectoryShapeChangeIsRejectedBeforeMutation(): void
    {
        $this->fixture = new FileSwitchFixture(['public/a' => 'old'], ['public/a/b.php' => 'new']);
        $this->expectExceptionMessage('update_switch_path_collision');
        $this->fixture->plan();
    }

    public function testChangingTheAuthenticatedArchiveIsRejected(): void
    {
        $this->fixture = new FileSwitchFixture();
        file_put_contents($this->fixture->archive, 'changed', FILE_APPEND);
        $this->expectExceptionMessage('update_switch_source_invalid');
        $this->fixture->plan();
    }

    public function testNonpositiveBudgetIsRejected(): void
    {
        $this->fixture = new FileSwitchFixture();
        $this->expectExceptionMessage('update_switch_request_invalid');
        $this->runner()->step($this->fixture->plan(), self::JOB, static function (): void {
        }, 0);
    }

    private function runner(): ResumableFileSwap
    {
        return new ResumableFileSwap($this->fixture->live, $this->fixture->private);
    }

    private function state(): string
    {
        return $this->fixture->private . '/switch-' . self::JOB . '/progress.json';
    }
}
