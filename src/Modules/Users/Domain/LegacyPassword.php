<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use InvalidArgumentException;
use SensitiveParameter;

/** Imported credentials are algorithm-tagged, bounded and upgraded by the existing throttled login flow. */
final class LegacyPassword
{
    private const ALPHABET = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function supported(string $engine, #[SensitiveParameter] string $hash): bool
    {
        if ($engine === 'mybb') {
            return preg_match('/^[a-f0-9]{32}$/D', $hash) === 1;
        }
        if ($engine === 'smf' && preg_match('/^[a-f0-9]{40}$/D', $hash) === 1) {
            return true;
        }
        if (
            in_array($engine, ['smf', 'phpbb'], true)
            && preg_match('/^\$2[aby]\$(0[4-9]|1[0-2])\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1
        ) {
            return true;
        }
        if (
            $engine === 'phpbb' && strlen($hash) === 34
            && in_array(substr($hash, 0, 3), ['$H$', '$P$'], true)
            && strspn(substr($hash, 3), self::ALPHABET) === 31
        ) {
            $power = strpos(self::ALPHABET, $hash[3]);
            return $power !== false && $power >= 7 && $power <= 16;
        }
        return false;
    }

    public static function encode(
        string $engine,
        #[SensitiveParameter] string $hash,
        #[SensitiveParameter] string $context = ''
    ): string {
        $tag = '$nl$' . $engine . '$' . base64_encode($context) . '$' . $hash;
        if (
            !in_array($engine, ['smf', 'mybb', 'phpbb'], true) || strlen($tag) > 255
            || preg_match('/[\x00-\x20\x7f]/', $hash) === 1 || strlen($context) > 96
        ) {
            throw new InvalidArgumentException('import_password_unsupported');
        }
        return $tag;
    }

    public static function verify(#[SensitiveParameter] string $password, string $tag): bool
    {
        if (strlen($password) > 4096 || strlen($tag) > 255) {
            return false;
        }
        $parts = explode('$', $tag, 5);
        if (count($parts) !== 5 || $parts[1] !== 'nl') {
            return false;
        }
        [, , $engine, $encoded, $hash] = $parts;
        $context = base64_decode($encoded, true);
        if ($context === false || base64_encode($context) !== $encoded || strlen($context) > 96) {
            return false;
        }
        if ($engine === 'mybb') {
            return preg_match('/^[a-f0-9]{32}$/D', $hash) === 1
                && hash_equals($hash, md5(md5($context) . md5($password)));
        }
        if ($engine === 'smf') {
            $input = mb_strtolower($context, 'UTF-8') . $password;
            if (preg_match('/^[a-f0-9]{40}$/D', $hash) === 1) {
                return hash_equals($hash, sha1($input));
            }
            return self::bcrypt($input, $hash);
        }
        if ($engine === 'phpbb') {
            if (str_starts_with($hash, '$H$') || str_starts_with($hash, '$P$')) {
                return self::portable($password, $hash);
            }
            return self::bcrypt($password, $hash);
        }
        return false;
    }

    private static function bcrypt(#[SensitiveParameter] string $input, string $hash): bool
    {
        return strlen($input) <= 72 && preg_match('/^\$2[aby]\$(0[4-9]|1[0-2])\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1
            && password_verify($input, $hash);
    }

    private static function portable(#[SensitiveParameter] string $password, string $hash): bool
    {
        if (strlen($hash) !== 34 || strspn(substr($hash, 3), self::ALPHABET) !== 31) {
            return false;
        }
        $power = strpos(self::ALPHABET, $hash[3]);
        if ($power === false || $power < 7 || $power > 16) {
            return false;
        }
        $digest = md5(substr($hash, 4, 8) . $password, true);
        for ($iteration = 0; $iteration < (1 << $power); ++$iteration) {
            $digest = md5($digest . $password, true);
        }
        $encoded = '';
        $buffer = 0;
        $bits = 0;
        for ($index = 0; $index < 16; ++$index) {
            $buffer |= ord($digest[$index]) << $bits;
            $bits += 8;
            while ($bits >= 6) {
                $encoded .= self::ALPHABET[$buffer & 63];
                $buffer >>= 6;
                $bits -= 6;
            }
        }
        if ($bits > 0) {
            $encoded .= self::ALPHABET[$buffer & 63];
        }
        return hash_equals($hash, substr($hash, 0, 12) . $encoded);
    }
}
