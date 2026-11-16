<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use RuntimeException;

/** SQL identifiers are fixed per adapter; only a validated prefix is configurable. No source PHP is loaded. */
final class SourceSchema
{
    public const SPECS = [
        'smf' => [
            'categories' => ['categories', 'id_cat', ['name' => 'name', 'sort' => 'cat_order']],
            'groups' => ['membergroups', 'id_group', ['name' => 'group_name']],
            'users' => ['members', 'id_member', ['name' => 'member_name', 'email' => 'email_address',
                'hash' => 'passwd', 'group' => 'id_group', 'groups' => 'additional_groups',
                'time' => 'date_registered', 'active' => 'is_activated', 'signature' => 'signature']],
            'forums' => ['boards', 'id_board', ['name' => 'name', 'description' => 'description',
                'parent' => 'id_parent', 'category' => 'id_cat', 'sort' => 'board_order']],
            'threads' => ['topics', 'id_topic', ['forum' => 'id_board', 'user' => 'id_member_started',
                'first' => 'id_first_msg', 'locked' => 'locked', 'sticky' => 'is_sticky',
                'visible' => 'approved', 'moved' => 'id_redirect_topic']],
            'posts' => ['messages', 'id_msg', ['thread' => 'id_topic', 'user' => 'id_member',
                'body' => 'body', 'time' => 'poster_time', 'visible' => 'approved']],
        ],
        'mybb' => [
            'groups' => ['usergroups', 'gid', ['name' => 'title']],
            'users' => ['users', 'uid', ['name' => 'username', 'email' => 'email', 'hash' => 'password',
                'salt' => 'salt', 'group' => 'usergroup', 'groups' => 'additionalgroups', 'time'
                    => 'regdate', 'signature' => 'signature']],
            'forums' => ['forums', 'fid', ['name' => 'name', 'description' => 'description',
                'parent' => 'pid', 'sort' => 'disporder', 'kind' => 'type']],
            'threads' => ['threads', 'tid', ['forum' => 'fid', 'user' => 'uid', 'name' => 'subject',
                'time' => 'dateline', 'first' => 'firstpost', 'locked' => 'closed', 'sticky' => 'sticky',
                'visible' => 'visible']],
            'posts' => ['posts', 'pid', ['thread' => 'tid', 'user' => 'uid', 'body' => 'message',
                'time' => 'dateline', 'visible' => 'visible']],
        ],
        'phpbb' => [
            'groups' => ['groups', 'group_id', ['name' => 'group_name']],
            'users' => ['users', 'user_id', ['name' => 'username', 'email' => 'user_email',
                'hash' => 'user_password', 'group' => 'group_id', 'active' => 'user_type', 'time'
                    => 'user_regdate', 'signature' => 'user_sig']],
            'forums' => ['forums', 'forum_id', ['name' => 'forum_name', 'description' => 'forum_desc',
                'parent' => 'parent_id', 'sort' => 'left_id', 'kind' => 'forum_type']],
            'threads' => ['topics', 'topic_id', ['forum' => 'forum_id', 'user' => 'topic_poster',
                'name' => 'topic_title', 'time' => 'topic_time', 'first' => 'topic_first_post_id',
                'locked' => 'topic_status', 'sticky' => 'topic_type', 'visible' => 'topic_visibility',
                'moved' => 'topic_moved_id']],
            'posts' => ['posts', 'post_id', ['thread' => 'topic_id', 'user' => 'poster_id',
                'body' => 'post_text', 'time' => 'post_time', 'visible' => 'post_visibility']],
        ],
    ];

    public static function phases(string $engine): array
    {
        $base = array_keys(self::SPECS[$engine] ?? throw new RuntimeException('import_engine_unsupported'));
        return [...$base, ...($engine === 'smf' ? ['private_sent'] : []), 'private_inbox', 'attachments',
            ...($engine === 'phpbb' ? ['private_files'] : []), 'polls', 'poll_options', 'poll_votes'];
    }
}
