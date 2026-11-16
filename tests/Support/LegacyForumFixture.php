<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Doctrine\DBAL\Connection;

/** Reduced independent schema fixtures derived from official SMF2.1/MyBB1.8/phpBB3.3 column contracts. */
final class LegacyForumFixture
{
    public static function install(Connection $db, string $engine): void
    {
        $definitions = match ($engine) {
            'smf' => [
                'settings' => 'variable:s,value:s', 'categories' => 'id_cat,name:s,cat_order',
                'membergroups' => 'id_group,group_name:s',
                'members' => 'id_member,member_name:s,email_address:s,passwd:s,id_group,additional_groups:s,'
                    . 'date_registered,is_activated,signature:s',
                'boards' => 'id_board,name:s,description:s,id_parent,id_cat,board_order',
                'topics' => 'id_topic,id_board,id_member_started,id_first_msg,locked,is_sticky,approved,'
                    . 'id_redirect_topic,id_poll',
                'messages' => 'id_msg,id_topic,id_member,body:s,poster_time,approved,subject:s',
                'personal_messages' => 'id_pm,id_member_from,subject:s,msgtime,body:s,deleted_by_sender',
                'pm_recipients' => 'id_pm,id_member,deleted',
                'attachments' => 'id_attach,id_msg,id_member,filename:s,file_h'
                    . 'ash:s,id_folder,size,approved,attachment_type',
                'polls' => 'id_poll,id_member,question:s,max_votes,expire_time,voting_locked,hide_results',
                'poll_choices' => 'id_poll,id_choice,label:s,votes', 'log_polls' => 'id_poll,id_member,id_choice',
            ],
            'mybb' => [
                'datacache' => 'title:s,cache:s', 'usergroups' => 'gid,title:s,isbannedgroup',
                'users' => 'uid,username:s,email:s,password:s,salt:s,usergroup,additionalgroups:s,regdate,signature:s',
                'forums' => 'fid,name:s,description:s,pid,disporder,type:s',
                'threads' => 'tid,fid,uid,subject:s,dateline,firstpost,closed:s,sticky,visible',
                'posts' => 'pid,tid,uid,message:s,dateline,visible',
                'privatemessages' => 'pmid,uid,fromid,subject:s,dateline,message:s',
                'attachments' => 'aid,pid,uid,filename:s,attachname:s,filesize,visible,dateuploaded',
                'polls' => 'pid,tid,question:s,multiple,maxoptions,numopt'
                    . 'ions,timeout,dateline,closed,options:s,votes:s',
                'pollvotes' => 'vid,pid,uid,voteoption',
            ],
            'phpbb' => [
                'config' => 'config_name:s,config_value:s', 'groups' => 'group_id,group_name:s',
                'users' => 'user_id,username:s,user_email:s,user_password:s,group_id,user_type,user_regdate,user_sig:s',
                'user_group' => 'user_id,group_id,user_pending',
                'forums' => 'forum_id,forum_name:s,forum_desc:s,parent_id,left_id,forum_type',
                'topics' => 'topic_id,forum_id,topic_poster,topic_title:s,topic_time,topic_first_post_id,'
                    . 'topic_status,topic_type,topic_visibility,topic_moved_id,poll_title:s,poll_max_options,'
                    . 'poll_start,poll_length',
                'posts' => 'post_id,topic_id,poster_id,post_text:s,post_time,post_visibility',
                'privmsgs' => 'msg_id,author_id,message_subject:s,message_time,message_text:s',
                'privmsgs_to' => 'msg_id,user_id,pm_deleted',
                'attachments' => 'attach_id,post_msg_id,poster_id,real_filename:s,physical_filename:s,filesize,'
                    . 'filetime,in_message,is_orphan',
                'poll_options' => 'topic_id,poll_option_id,poll_option_text:s,poll_option_total',
                'poll_votes' => 'topic_id,poll_option_id,vote_user_id',
            ],
        };
        foreach ($definitions as $table => $fields) {
            $columns = [];
            foreach (explode(',', $fields) as $field) {
                $string = str_ends_with($field, ':s');
                $name = $string ? substr($field, 0, -2) : $field;
                $columns[] = $db->quoteIdentifier($name)
                    . ($string ? " TEXT NOT NULL DEFAULT ('')" : ' BIGINT NOT NULL DEFAULT 0');
            }
            // The source may be a separate MySQL database kept between tests (PostgreSQL target runs).
            $db->executeStatement('DROP TABLE IF EXISTS ' . $db->quoteIdentifier('legacy_' . $table));
            $db->executeStatement('CREATE TABLE ' . $db->quoteIdentifier('legacy_' . $table) . ' ('
                . implode(', ', $columns) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        $hash = match ($engine) {
            'smf' => sha1('migrated' . 'fixture-password'),
            'mybb' => md5(md5('salt') . md5('fixture-password')),
            'phpbb' => password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4]),
        };
        $rows = match ($engine) {
            'smf' => [
                'settings' => [['variable' => 'smfVersion', 'value' => '2.1.7']],
                'categories' => [['id_cat' => 1, 'name' => 'Category']],
                'membergroups' => [['id_group' => 1, 'group_name' => 'Former administrators']],
                'members' => [['id_member' => 2, 'member_name' => 'Migrated', 'email_address'
                    => 'migrated@example.test',
                    'passwd' => $hash, 'id_group' => 1, 'date_registered' => 1700000000, 'is_activated'
                        => 1, 'signature' => '[i]İmza[/i]']],
                'boards' => [['id_board' => 1, 'name' => 'Forum', 'id_cat' => 1]],
                'topics' => [['id_topic' => 1, 'id_board' => 1, 'id_member_started' => 2, 'id_first_msg' => 1,
                    'approved' => 1, 'id_poll' => 1]],
                'messages' => [['id_msg' => 1, 'id_topic' => 1, 'id_member' => 2, 'body' => '[b]Çığ[/b]',
                    'subject' => 'Topic', 'poster_time' => 1700000000, 'approved' => 1]],
                'personal_messages' => [['id_pm' => 1, 'id_member_from' => 2, 'subject' => 'Private',
                    'msgtime' => 1700000000, 'body' => 'Private body']],
                'pm_recipients' => [['id_pm' => 1, 'id_member' => 2]],
                'polls' => [['id_poll' => 1, 'id_member' => 2, 'question' => 'Question?', 'max_votes' => 1]],
                'poll_choices' => [['id_poll' => 1, 'id_choice' => 0, 'label' => 'Yes', 'votes' => 3]],
                'log_polls' => [['id_poll' => 1, 'id_member' => 2, 'id_choice' => 0]],
            ],
            'mybb' => [
                'datacache' => [['title' => 'version', 'cache' => serialize(['version' => '1.8.40'])]],
                'usergroups' => [['gid' => 4, 'title' => 'Former administrators']],
                'users' => [['uid' => 2, 'username' => 'Migrated', 'email' => 'migrated@example.test',
                    'password' => $hash, 'salt' => 'salt', 'usergroup' => 4, 'regdate' => 1700000000,
                        'signature' => '[i]İmza[/i]']],
                'forums' => [['fid' => 1, 'name' => 'Forum', 'type' => 'f']],
                'threads' => [['tid' => 1, 'fid' => 1, 'uid' => 2, 'subject' => 'Topic', 'dateline' => 1700000000,
                    'firstpost' => 1, 'closed' => '0', 'visible' => 1]],
                'posts' => [['pid' => 1, 'tid' => 1, 'uid' => 2, 'message' => '[b]Çığ[/b]',
                    'dateline' => 1700000000, 'visible' => 1]],
                'privatemessages' => [['pmid' => 1, 'uid' => 2, 'fromid' => 2, 'subject' => 'Private',
                    'dateline' => 1700000000, 'message' => 'Private body']],
                'polls' => [['pid' => 1, 'tid' => 1, 'question' => 'Question?', 'maxoptions' => 1,
                    'numoptions' => 1, 'dateline' => 1700000000, 'options' => 'Yes', 'votes' => '3']],
                'pollvotes' => [['vid' => 1, 'pid' => 1, 'uid' => 2, 'voteoption' => 1]],
            ],
            'phpbb' => [
                'config' => [['config_name' => 'version', 'config_value' => '3.3.15']],
                'groups' => [['group_id' => 5, 'group_name' => 'Former administrators']],
                'users' => [['user_id' => 2, 'username' => 'Migrated', 'user_email' => 'migrated@example.test',
                    'user_password' => $hash, 'group_id' => 5, 'user_type' => 3, 'user_regdate'
                        => 1700000000, 'user_sig' => '[i]İmza[/i]']],
                'user_group' => [['user_id' => 2, 'group_id' => 5]],
                'forums' => [['forum_id' => 1, 'forum_name' => 'Forum', 'forum_type' => 1]],
                'topics' => [['topic_id' => 1, 'forum_id' => 1, 'topic_poster' => 2, 'topic_title' => 'Topic',
                    'topic_time' => 1700000000, 'topic_first_post_id' => 1, 'topic_visibility' => 1,
                    'poll_title' => 'Question?', 'poll_max_options' => 1, 'poll_start' => 1700000000]],
                'posts' => [['post_id' => 1, 'topic_id' => 1, 'poster_id' => 2,
                    'post_text' => '<r><B><s>[b]</s>Çığ<e>[/b]</e></B></r>', 'post_time' => 1700000000,
                    'post_visibility' => 1]],
                'privmsgs' => [['msg_id' => 1, 'author_id' => 2, 'message_subject' => 'Private',
                    'message_time' => 1700000000, 'message_text' => 'Private body']],
                'privmsgs_to' => [['msg_id' => 1, 'user_id' => 2]],
                'poll_options' => [['topic_id' => 1, 'poll_option_id' => 0, 'poll_option_text' => 'Yes',
                    'poll_option_total' => 3]],
                'poll_votes' => [['topic_id' => 1, 'poll_option_id' => 0, 'vote_user_id' => 2]],
            ],
        };
        foreach ($rows as $table => $records) {
            foreach ($records as $row) {
                $db->insert('legacy_' . $table, $row);
            }
        }
    }
}
