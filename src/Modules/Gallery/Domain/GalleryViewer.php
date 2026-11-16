<?php

declare(strict_types=1);

namespace Nolviko\Modules\Gallery\Domain;

use Nolviko\Modules\Permissions\Domain\SubjectPermissions;

/**
 * Who is looking at the gallery: a guest (id null), a member, an administrator (`admin.access`: sees and manages
 * everything) or a moderator (`moderation.report.handle` anywhere: sees and approves pending images).
 */
final readonly class GalleryViewer
{
    public function __construct(
        public ?int $id,
        public bool $admin = false,
        public bool $moderator = false,
    ) {
    }

    public static function of(SubjectPermissions $subject): self
    {
        $member = $subject->userId !== null;

        return new self(
            $subject->userId,
            $member && $subject->allows('admin.access'),
            $member && ($subject->allows('moderation.report.handle')
                || $subject->forumsAllowing('moderation.report.handle') !== []),
        );
    }
}
