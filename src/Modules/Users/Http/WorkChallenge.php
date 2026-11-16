<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use Nolviko\Core\Session\Session;
use Nolviko\Modules\Users\Domain\RegistrationChallenge;

/**
 * Built-in proof-of-work challenge (ADR-035). The form carries a random salt and a difficulty; `challenge.js` finds a
 * nonce whose SHA-256(salt . nonce) starts with DIFFICULTY zero bits and puts it in a hidden field. The salt lives in
 * the session only and is forgotten on the first answer. Browsers without JavaScript or Web Crypto answer the sum
 * question instead (the same QuestionChallenge, presented in the same form).
 */
final class WorkChallenge implements RegistrationChallenge
{
    public const FIELD = 'work_nonce';

    public const DIFFICULTY = 16;

    private const KEY = 'register.work';

    private readonly Closure $salt;

    /**
     * @param (Closure(): string)|null $salt Null means 16 random bytes, hex encoded.
     */
    public function __construct(
        private readonly Session $session,
        private readonly QuestionChallenge $fallback,
        ?Closure $salt = null,
    ) {
        $this->salt = $salt ?? static fn(): string => bin2hex(random_bytes(16));
    }

    public function present(): ?array
    {
        $salt = ($this->salt)();
        $this->session->set(self::KEY, $salt);
        $question = $this->fallback->present() ?? ['id' => '', 'parameters' => []];

        return $question + ['work' => ['salt' => $salt, 'bits' => self::DIFFICULTY, 'field' => self::FIELD]];
    }

    public function passes(array $input): bool
    {
        $salt = $this->session->get(self::KEY);
        $this->session->remove(self::KEY);
        $nonce = is_string($input[self::FIELD] ?? null) ? $input[self::FIELD] : '';

        if ($nonce === '') {
            return $this->fallback->passes($input);
        }

        // The sum is still in the session; forget it too, so neither answer can be replayed.
        $this->fallback->passes([]);

        return is_string($salt) && preg_match('/^[0-9]{1,12}$/D', $nonce) === 1
            && self::leadingZeroBits(hash('sha256', $salt . $nonce, true)) >= self::DIFFICULTY;
    }

    public static function leadingZeroBits(string $digest): int
    {
        $bits = 0;

        foreach (str_split($digest) as $byte) {
            $value = ord($byte);

            if ($value === 0) {
                $bits += 8;
                continue;
            }

            for ($mask = 0x80; ($value & $mask) === 0; $mask >>= 1) {
                ++$bits;
            }

            return $bits;
        }

        return $bits;
    }
}
