<?php

declare(strict_types=1);

/*
 * English texts for reports and moderation pages (M04.07a+).
 */

return [
    'moderation.report.title' => 'Report a post',
    'moderation.report.intro' => 'Tell the moderators what is wrong with this post. Only they see your report; '
        . 'the author does not learn who reported it.',
    'moderation.report.reason' => 'Reason',
    'moderation.report.submit' => 'Send report',
    'moderation.report.back' => 'Back to the post',
    'moderation.queue.title' => 'Moderation queue',
    'moderation.queue.tabs' => 'Queue sections',
    'moderation.queue.reports' => 'Reports',
    'moderation.queue.posts' => 'Posts waiting for approval',
    'moderation.queue.users' => 'Accounts waiting for approval',
    'moderation.queue.empty' => 'Nothing is waiting here.',
    'moderation.queue.reported_by' => 'Reported by {name}: {reason}',
    'moderation.queue.note' => 'Note for the moderation log',
    'moderation.queue.resolve' => 'Resolve',
    'moderation.queue.dismiss' => 'Dismiss',
    'moderation.queue.review' => 'Review and approve',
    'moderation.queue.older' => 'Older items',
    'moderation.decision.title' => 'Report decision',
    'moderation.decision.back' => 'Back to the queue',
    'moderation.queue.pick' => 'Select item {id}',
    'moderation.bulk.title' => 'Moderation result',
    'moderation.bulk.approve' => 'Approve selected',
    'moderation.bulk.reject' => 'Reject selected',
    'moderation.bulk.done' => 'done',
    'moderation.bulk.summary' => '{applied, number} of {total, number} applied.',
    'moderation.bulk.item' => 'Item {id}: {outcome}',
    'moderation.user.title' => 'Sanctions for {name}',
    'moderation.user.not_banned' => 'This user is not banned.',
    'moderation.user.banned_forever' => 'This user is banned until the ban is lifted.',
    'moderation.user.banned_until' => 'This user is banned until {until}.',
    'moderation.user.points' => 'Active warning points: {points, number}',
    'moderation.user.reason' => 'Reason (kept in the moderation log)',
    'moderation.user.days' => 'Days (empty or 0 means no end)',
    'moderation.user.ban' => 'Ban this user',
    'moderation.user.lift' => 'Lift the ban',
    'moderation.user.warn' => 'Warn this user',
    'moderation.user.warn_points' => 'Points',
];
