<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Domain;

/**
 * Fixed legacy endpoint grammar. No host, destination URL or arbitrary redirect parameter is accepted.
 *
 * SMF writes its own addresses with `;` between parameters (`index.php?action=dlattach;topic=5.0;attach=3`), which
 * PHP reads as one parameter; those are split here (beta.18). `index.php?msg=N` is SMF's short post address.
 */
final class LegacyLink
{
    public static function parse(string $engine, string $path, array $query): ?array
    {
        $entity = null;
        $value = null;
        if ($engine === 'smf' && $path === 'index.php') {
            $query = self::smfQuery($query);
            if (($query['action'] ?? null) === 'dlattach') {
                $entity = 'attachments';
                $value = $query['attach'] ?? null;
            } elseif (($query['action'] ?? null) === 'profile') {
                $entity = 'users';
                $value = $query['u'] ?? null;
            } elseif (is_string($query['msg'] ?? null) && !isset($query['topic'])) {
                $entity = 'posts';
                $value = $query['msg'];
            } elseif (is_string($query['topic'] ?? null)) {
                if (
                    preg_match(
                        '/^([1-9][0-9]{0,18})(?:\.(?:msg([1-9][0-9]{0,18})|[0-9]+))?$/D',
                        $query['topic'],
                        $match
                    ) !== 1
                ) {
                    return null;
                }
                $entity = isset($match[2]) ? 'posts' : 'threads';
                $value = $match[2] ?? $match[1];
            } elseif (is_string($query['board'] ?? null)) {
                $entity = 'forums';
                $value = explode('.', $query['board'], 2)[0];
            }
        } elseif ($engine === 'mybb') {
            [$entity, $key] = match ($path) {
                'showthread.php' => isset($query['pid']) ? ['posts', 'pid'] : ['threads', 'tid'],
                'forumdisplay.php' => ['forums', 'fid'],
                'member.php' => ($query['action'] ?? '') === 'profile' ? ['users', 'uid'] : [null, ''],
                'attachment.php' => ['attachments', 'aid'],
                default => [null, ''],
            };
            $value = $query[$key] ?? null;
        } elseif ($engine === 'phpbb') {
            [$entity, $key] = match ($path) {
                'viewtopic.php' => isset($query['p']) ? ['posts', 'p'] : ['threads', 't'],
                'viewforum.php' => ['forums', 'f'],
                'memberlist.php' => ($query['mode'] ?? '') === 'viewprofile' ? ['users', 'u'] : [null, ''],
                'download/file.php' => ['attachments', 'id'],
                default => [null, ''],
            };
            $value = $query[$key] ?? null;
        }
        if (
            $entity === null || !is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1
            || strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0
        ) {
            return null;
        }
        return ['entity' => $entity, 'source' => (int) $value];
    }

    /**
     * SMF's `;`-separated parameters as separate ones; a parameter given with `&` as well keeps its first value.
     * At most 16 parameters are read.
     *
     * @param array<array-key, mixed> $query
     * @return array<string, mixed>
     */
    private static function smfQuery(array $query): array
    {
        $result = [];
        $count = 0;
        foreach ($query as $key => $value) {
            $parts = is_string($value) ? explode(';', $value) : [$value];
            $first = array_shift($parts);
            $pairs = [[(string) $key, $first]];
            foreach ($parts as $part) {
                [$name, $item] = array_pad(explode('=', (string) $part, 2), 2, '');
                $pairs[] = [$name, $item];
            }
            foreach ($pairs as [$name, $item]) {
                if (++$count > 16) {
                    return $result;
                }
                if ($name !== '' && !array_key_exists($name, $result)) {
                    $result[$name] = $item;
                }
            }
        }
        return $result;
    }
}
