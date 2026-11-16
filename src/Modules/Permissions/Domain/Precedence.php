<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * The ADR-011 precedence rules as one pure function (no storage, no I/O); the truth table in the ADR is its
 * test (`tests/Unit/Permissions/PrecedenceTruthTableTest.php`).
 *
 * Bool: a `never` anywhere denies; otherwise the first level with a rule decides (user+forum, user,
 * groups+forum, groups global; forum levels read the chain from the forum upwards), allow if any rule there
 * allows; otherwise the catalog default (deny). Int/enum: the first level with a value decides, and the most
 * generous of its values wins; otherwise the definition's default. Stored values that no longer fit the
 * definition are ignored and reported, never treated as allow.
 */
final class Precedence
{
    public const NEVER = 'never';

    public static function decide(PermissionDefinition $definition, PermissionRules $rules): PermissionDecision
    {
        $ignored = [];
        $levels = [
            'user_forum' => self::nearestForum($rules->userForum),
            'user' => [null, $rules->user],
            'group_forum' => self::nearestForum($rules->groupForum),
            'group' => [null, $rules->group],
        ];
        $isBool = $definition->type === PermissionType::Bool;

        if ($isBool && self::containsNever($rules)) {
            return new PermissionDecision(false, PermissionDecision::NEVER);
        }

        foreach ($levels as $level => [$depth, $values]) {
            $valid = [];

            foreach ($values as $value) {
                if ($isBool && $value === self::NEVER) {
                    continue;
                }

                try {
                    $valid[] = $definition->decode($value);
                } catch (PermissionError) {
                    $ignored[] = $value;
                }
            }

            if ($valid === []) {
                continue;
            }

            $decided = $isBool ? in_array(true, $valid, true) : $definition->mostGenerous($valid);

            return new PermissionDecision($decided, $level, $depth, $ignored);
        }

        return new PermissionDecision($definition->defaultValue(), PermissionDecision::DEFAULT, null, $ignored);
    }

    /**
     * @param list<list<string>> $chain
     *
     * @return array{0: int|null, 1: list<string>} Depth of the nearest forum with rules, and its rules.
     */
    private static function nearestForum(array $chain): array
    {
        foreach ($chain as $depth => $values) {
            if ($values !== []) {
                return [$depth, $values];
            }
        }

        return [null, []];
    }

    private static function containsNever(PermissionRules $rules): bool
    {
        $all = [...$rules->user, ...$rules->group];

        foreach ([...$rules->userForum, ...$rules->groupForum] as $values) {
            $all = [...$all, ...$values];
        }

        return in_array(self::NEVER, $all, true);
    }
}
