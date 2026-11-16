<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use Nolviko\Core\Session\Session;
use Nolviko\Modules\Users\Domain\RegistrationChallenge;

/**
 * The built-in challenge (M02.03a): a small sum, asked in the visitor's language, answered in a number field.
 * The expected answer stays in the session (never in the page) and is removed on the first answer, so a
 * question cannot be retried by a script.
 */
final class QuestionChallenge implements RegistrationChallenge
{
    public const FIELD = 'challenge';

    private const KEY = 'register.challenge';

    private readonly Closure $random;

    /**
     * @param (Closure(int, int): int)|null $random Null means random_int().
     */
    public function __construct(private readonly Session $session, ?Closure $random = null)
    {
        $this->random = $random ?? static fn(int $min, int $max): int => random_int($min, $max);
    }

    public function present(): ?array
    {
        $first = ($this->random)(2, 9);
        $second = ($this->random)(2, 9);
        $this->session->set(self::KEY, $first + $second);

        return ['id' => 'auth.challenge.sum', 'parameters' => ['first' => $first, 'second' => $second]];
    }

    public function passes(array $input): bool
    {
        $expected = $this->session->get(self::KEY);
        $this->session->remove(self::KEY);
        $answer = is_string($input[self::FIELD] ?? null) ? trim($input[self::FIELD]) : '';

        return is_int($expected) && preg_match('/^[0-9]{1,3}$/D', $answer) === 1 && (int) $answer === $expected;
    }
}
