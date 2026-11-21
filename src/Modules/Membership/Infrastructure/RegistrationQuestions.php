<?php

declare(strict_types=1);

namespace Nolviko\Modules\Membership\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Community\Infrastructure\CommunityAccess;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Throwable;

/**
 * The administrator's own verification questions (roadmap 31): a question/answer pool per language, asked on the
 * registration form next to the built-in challenge. A visitor gets a random question of their page language; a
 * language without questions asks none. Answers are compared without case and extra spaces; any listed answer
 * counts.
 */
final readonly class RegistrationQuestions
{
    public const MAX = 200;

    public function __construct(private Connection $db)
    {
    }

    /** @return list<array{id: int, locale: string, question: string, answers: list<string>}> */
    public function all(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT id, locale, question, answers FROM registration_questions '
                . 'ORDER BY locale, id LIMIT ' . self::MAX);
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'locale' => (string) $row['locale'],
            'question' => (string) $row['question'], 'answers' => self::split((string) $row['answers'])], $rows);
    }

    /**
     * @param list<string> $locales Installed languages.
     *
     * @throws RegistrationRejected `question_invalid`.
     */
    public function add(string $locale, string $question, string $answers, array $locales): void
    {
        $question = CommunityAccess::text($question, 255);
        $list = self::split($answers);

        if (
            $question === '' || $list === [] || !in_array($locale, $locales, true)
            || (int) $this->db->fetchOne('SELECT COUNT(*) FROM registration_questions') >= self::MAX
        ) {
            throw new RegistrationRejected(['question_invalid']);
        }

        $this->db->insert('registration_questions', ['locale' => $locale, 'question' => $question,
            'answers' => mb_substr(implode("\n", $list), 0, 1000), 'created_at' => gmdate('Y-m-d H:i:s.000000')]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('registration_questions', ['id' => $id]);
    }

    /** @return array{id: int, question: string}|null A random question of that language. */
    public function pick(string $locale): ?array
    {
        try {
            $ids = $this->db->fetchFirstColumn(
                'SELECT id FROM registration_questions WHERE locale = ? ORDER BY id LIMIT ' . self::MAX,
                [$locale],
            );

            if ($ids === []) {
                return null;
            }

            $id = (int) $ids[random_int(0, count($ids) - 1)];
            $question = $this->db->fetchOne('SELECT question FROM registration_questions WHERE id = ?', [$id]);
        } catch (Throwable) {
            return null;
        }

        return $question === false ? null : ['id' => $id, 'question' => (string) $question];
    }

    public function answers(int $id, string $answer): bool
    {
        $stored = $this->db->fetchOne('SELECT answers FROM registration_questions WHERE id = ?', [$id]);
        $given = self::normal($answer);

        return $stored !== false && $given !== '' && in_array($given, array_map(
            self::normal(...),
            self::split((string) $stored),
        ), true);
    }

    /** @return list<string> */
    private static function split(string $answers): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(string $line): string => CommunityAccess::text($line, 100),
            preg_split('/\R|\|/u', $answers) ?: [],
        ), static fn(string $line): bool => $line !== '')));
    }

    private static function normal(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
