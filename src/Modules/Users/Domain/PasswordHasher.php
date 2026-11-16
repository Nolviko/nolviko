<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use SensitiveParameter;

/**
 * Password hashes: Argon2id where PHP has it (OWASP minimum m=19 MiB, t=2, p=1, about 35 ms in the Docker
 * reference), bcrypt cost 11 otherwise.
 *
 * bcrypt only reads the first 72 bytes, so for bcrypt the password is first reduced to a fixed 64-character
 * HMAC-SHA-384 (base64) digest: every byte counts and nothing is truncated silently. The digest is applied
 * to every bcrypt hash this class creates or verifies; hashes imported from other forums keep their own
 * verifier (M09). Passwords are `#[SensitiveParameter]`, so they never appear in stack traces.
 */
final class PasswordHasher
{
    public const ARGON2ID_OPTIONS = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

    public const BCRYPT_OPTIONS = ['cost' => 11];

    private const BCRYPT_PREHASH_KEY = 'forumengine-bcrypt-prehash-v1';

    private readonly string $algorithm;

    /**
     * @param bool|null $argon2 Null means "use Argon2id when this PHP supports it"; false forces bcrypt.
     */
    public function __construct(?bool $argon2 = null)
    {
        $this->algorithm = ($argon2 ?? defined('PASSWORD_ARGON2ID')) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($this->input($password, $this->algorithm), $this->algorithm, $this->options());
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        if (str_starts_with($hash, '$nl$')) {
            return LegacyPassword::verify($password, $hash);
        }

        $algorithm = password_get_info($hash)['algo'];

        return $algorithm !== null && password_verify($this->input($password, $algorithm), $hash);
    }

    /**
     * True when the hash uses another algorithm or weaker parameters: the login rehashes it (M02.04).
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options());
    }

    /**
     * Verify and, on success, hand an upgraded hash to $store (login): returns whether the password matched.
     *
     * @param callable(string): void $store
     */
    public function verifyAndUpgrade(#[SensitiveParameter] string $password, string $hash, callable $store): bool
    {
        if (!$this->verify($password, $hash)) {
            return false;
        }

        if ($this->needsRehash($hash)) {
            $store($this->hash($password));
        }

        return true;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @return array<string, int>
     */
    private function options(): array
    {
        return $this->algorithm === PASSWORD_ARGON2ID ? self::ARGON2ID_OPTIONS : self::BCRYPT_OPTIONS;
    }

    private function input(#[SensitiveParameter] string $password, ?string $algorithm): string
    {
        if ($algorithm !== PASSWORD_BCRYPT) {
            return $password;
        }

        return base64_encode(hash_hmac('sha384', $password, self::BCRYPT_PREHASH_KEY, true));
    }
}
