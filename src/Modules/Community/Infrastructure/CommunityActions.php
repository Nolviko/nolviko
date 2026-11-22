<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Command dispatch receives the authenticated subject; browser actor IDs are never used. */
final class CommunityActions
{
    public function __construct(
        private readonly MemberProfiles $profiles,
        private readonly CommunityInteractions $interactions,
        private readonly CommunityPolls $polls,
        private readonly CommunityNotifications $notifications,
        private readonly PrivateMedia $media,
        private readonly CommunityAccess $access,
        private readonly RateBuckets $buckets,
    ) {
    }

    public function run(SubjectPermissions $subject, array $fields, ?UploadedFile $file): void
    {
        $actor = $this->access->actor($subject);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $key = 'community.action:' . $actor;
        $this->buckets->ensure($key, $now);
        $wait = $this->buckets->hit($key, 2, $now);

        if ($wait > 0) {
            throw new RegistrationThrottled($wait);
        }

        $action = is_string($fields['action'] ?? null) ? $fields['action'] : '';
        $id = self::id($fields['target'] ?? null);

        switch ($action) {
            case 'profile':
                $this->profiles->save($subject, $fields);
                break;
            case 'block':
            case 'follow':
            case 'bookmark':
            case 'subscribe':
                $this->interactions->relationship($subject, $action, $id, ($fields['enable'] ?? '') === '1');
                break;
            case 'react':
                $this->interactions->react($subject, $id, self::field($fields, 'reaction'));
                break;
            case 'poll':
                $this->polls->create(
                    $subject,
                    $id,
                    self::field($fields, 'question'),
                    explode("\n", str_replace("\r", '', self::field($fields, 'options'))),
                    ($fields['multiple'] ?? '') === '1',
                    self::id($fields['days'] ?? '0'),
                    ($fields['allow_change'] ?? '') === '1',
                );
                break;
            case 'poll_edit':
                $labels = is_array($fields['labels'] ?? null) ? $fields['labels'] : [];
                $this->polls->edit(
                    $subject,
                    $id,
                    self::field($fields, 'question'),
                    array_map(static fn(mixed $label): string => is_string($label) ? $label : '', $labels),
                    explode("\n", str_replace("\r", '', self::field($fields, 'options'))),
                    self::id($fields['days'] ?? '0'),
                    ($fields['allow_change'] ?? '') === '1',
                );
                break;
            case 'poll_reset':
            case 'poll_remove':
                $this->polls->clear($subject, $id, $action === 'poll_remove');
                break;
            case 'vote_retract':
                $this->polls->retract($subject, $id);
                break;
            case 'vote':
                $selected = $fields['options'] ?? [];

                if (!is_array($selected) || array_filter($selected, is_array(...)) !== []) {
                    throw new RegistrationRejected(['community_invalid']);
                }

                $this->polls->vote($subject, $id, array_map(self::id(...), $selected));
                break;
            case 'solution':
                $this->polls->solution($subject, $id, self::id($fields['post'] ?? null));
                break;
            case 'read':
                $this->notifications->read($subject, $id);
                break;
            case 'upload':
            case 'avatar':
                if (!$file instanceof UploadedFile) {
                    throw new RegistrationRejected(['community_upload_invalid']);
                }

                $this->media->upload($subject, $file, $action === 'avatar' ? null : $id, $action === 'avatar');
                break;
            case 'reset_avatar':
                if (!$subject->allows('moderation.profile.manage')) {
                    throw new RegistrationRejected(['community_denied']);
                }

                $this->media->resetAvatar($subject, $id);
                break;
            case 'remove_media':
                $this->media->remove($subject, self::field($fields, 'media'));
                break;
            default:
                throw new RegistrationRejected(['community_invalid']);
        }
    }

    public static function id(mixed $value): int
    {
        return is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value) === 1 ? (int) $value : 0;
    }

    private static function field(array $fields, string $key): string
    {
        return is_string($fields[$key] ?? null) ? $fields[$key] : '';
    }
}
