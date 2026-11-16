<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Permissions;

use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionDecision;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Nolviko\Modules\Permissions\Domain\PermissionRules;
use Nolviko\Modules\Permissions\Domain\Precedence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M02.08: the ADR-011 truth table, row for row (docs/adr/ADR-011-permission-precedence.md).
 *
 * Cells: `A` allow, `D` deny, `N` never; several letters are several groups at one level (`AD` = G1 allow,
 * G2 deny). Forum levels are chains from the forum asked about (F) upwards: `['', 'A']` means no rule on F and
 * allow on its parent P.
 */
final class PrecedenceTruthTableTest extends TestCase
{
    private const VALUES = ['A' => '1', 'D' => '0', 'N' => 'never'];

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: list<string>, 3: string, 4: bool, 5: string}>
     */
    public static function boolRows(): array
    {
        // [L1 user+forum chain, L2 user, L3 groups+forum chain, L4 groups global, allowed, deciding reason]
        return [
            '1 no rules: default deny' => [[], '', [], '', false, 'default'],
            '2 global allow' => [[], '', [], 'A', true, 'group'],
            '3 global deny' => [[], '', [], 'D', false, 'group'],
            '4 generous within a level' => [[], '', [], 'AD', true, 'group'],
            '5 never beats allow' => [[], '', [], 'AN', false, 'never'],
            '6 never alone' => [[], '', [], 'N', false, 'never'],
            '7 forum deny before global allow' => [[], '', ['D'], 'A', false, 'group_forum'],
            '8 forum allow before global deny' => [[], '', ['A'], 'D', true, 'group_forum'],
            '9 global never still applies' => [[], '', ['A'], 'N', false, 'never'],
            '10 forum never' => [[], '', ['N'], 'A', false, 'never'],
            '11 generous within the forum level' => [[], '', ['DA'], '', true, 'group_forum'],
            '12 forum never among groups' => [[], '', ['DN'], 'A', false, 'never'],
            '13 inherits from the parent forum' => [[], '', ['', 'A'], 'D', true, 'group_forum'],
            '14 parent forum before global' => [[], '', ['', 'D'], 'A', false, 'group_forum'],
            '15 nearest forum wins (allow)' => [[], '', ['A', 'D'], '', true, 'group_forum'],
            '16 nearest forum wins (deny)' => [[], '', ['D', 'A'], '', false, 'group_forum'],
            '17 never on an ancestor is absolute' => [[], '', ['A', 'N'], '', false, 'never'],
            '18 user allow before group deny' => [[], 'A', [], 'D', true, 'user'],
            '19 user deny before group allow' => [[], 'D', [], 'A', false, 'user'],
            '20 never beats a user allow' => [[], 'A', [], 'N', false, 'never'],
            '21 user global before group forum rule' => [[], 'A', ['D'], '', true, 'user'],
            '22 user never' => [[], 'N', [], 'A', false, 'never'],
            '23 user forum override' => [['A'], '', [], 'D', true, 'user_forum'],
            '24 user forum deny before user allow' => [['D'], 'A', [], '', false, 'user_forum'],
            '25 user forum allow before user deny' => [['A'], 'D', [], '', true, 'user_forum'],
            '26 group forum never beats user forum allow' => [['A'], '', ['N'], '', false, 'never'],
            '27 user forum never' => [['N'], 'A', ['A'], 'A', false, 'never'],
            '28 all groups deny' => [[], '', [], 'DD', false, 'group'],
            '29 forum level decides over another group global allow' => [[], '', ['D'], 'A', false, 'group_forum'],
            '30 guest through the guests group' => [[], '', [], 'A', true, 'group'],
            '31 inheritance without a global rule' => [[], '', ['', 'A'], '', true, 'group_forum'],
            '32 deny and never' => [['D'], '', [], 'N', false, 'never'],
            '33 user forum override inherits up the tree' => [['', 'A'], 'D', [], '', true, 'user_forum'],
            '34 empty chains everywhere' => [['', ''], '', ['', '', ''], '', false, 'default'],
        ];
    }

    /**
     * @param list<string> $userForum
     * @param list<string> $groupForum
     */
    #[DataProvider('boolRows')]
    public function testBoolTruthTable(
        array $userForum,
        string $user,
        array $groupForum,
        string $group,
        bool $allowed,
        string $reason,
    ): void {
        $rules = new PermissionRules(
            array_map(self::cell(...), $userForum),
            self::cell($user),
            array_map(self::cell(...), $groupForum),
            self::cell($group),
        );

        $decision = Precedence::decide(PermissionDefinition::bool('forum.view'), $rules);

        self::assertSame($allowed, $decision->allowed());
        self::assertSame($reason, $decision->reason);
    }

    /**
     * @return array<string, array{0: string, 1: PermissionRules, 2: int|string, 3: string}>
     */
    public static function typedRows(): array
    {
        return [
            'T1 edit window default is the minimum' => [
                'forum.post.edit_window_minutes',
                new PermissionRules(),
                0,
                'default',
            ],
            'T2 edit window: most generous group' => [
                'forum.post.edit_window_minutes',
                new PermissionRules(group: ['30', '60']),
                60,
                'group',
            ],
            'T3 edit window: forum level first' => [
                'forum.post.edit_window_minutes',
                new PermissionRules(groupForum: [['5']], group: ['600']),
                5,
                'group_forum',
            ],
            'T4 flood interval: lower is generous' => [
                'forum.post.flood_interval_seconds',
                new PermissionRules(group: ['30', '10']),
                10,
                'group',
            ],
            'T5 approval: most generous option' => [
                'forum.post.approval',
                new PermissionRules(group: ['all', 'first_posts']),
                'first_posts',
                'group',
            ],
            'T6 approval default is the most restrictive' => [
                'forum.post.approval',
                new PermissionRules(),
                'all',
                'default',
            ],
            'T7 approval: user override first' => [
                'forum.post.approval',
                new PermissionRules(user: ['none'], group: ['all']),
                'none',
                'user',
            ],
            'T8 flood interval default is the maximum' => [
                'forum.post.flood_interval_seconds',
                new PermissionRules(),
                3600,
                'default',
            ],
        ];
    }

    #[DataProvider('typedRows')]
    public function testTypedTruthTable(string $key, PermissionRules $rules, int|string $expected, string $reason): void
    {
        $definition = PermissionCatalog::core(strict: true)->find($key);
        self::assertNotNull($definition);

        $decision = Precedence::decide($definition, $rules);

        self::assertSame($expected, $decision->value);
        self::assertSame($reason, $decision->reason);
    }

    public function testInvalidStoredValuesAreIgnoredAndReportedNeverAllowed(): void
    {
        $bool = Precedence::decide(
            PermissionDefinition::bool('forum.view'),
            new PermissionRules(user: ['yes'], group: ['0']),
        );
        $removedOption = Precedence::decide(
            PermissionDefinition::enum('forum.post.approval', ['all', 'none']),
            new PermissionRules(group: ['first_posts']),
        );

        self::assertFalse($bool->allowed());
        self::assertSame(['group', ['yes']], [$bool->reason, $bool->ignored]);
        self::assertSame(['all', PermissionDecision::DEFAULT, ['first_posts']], [
            $removedOption->value,
            $removedOption->reason,
            $removedOption->ignored,
        ]);
    }

    public function testDepthNamesTheForumThatDecided(): void
    {
        $rules = new PermissionRules(groupForum: [[], [], ['1']]);

        self::assertSame(2, Precedence::decide(PermissionDefinition::bool('forum.view'), $rules)->forumDepth);
    }

    public function testDefaultsAndDirectionsAreValidated(): void
    {
        self::assertTrue((new PermissionDefinition('a.b', PermissionDefinition::bool('a.b')->type, default: true))
            ->defaultValue());

        $this->expectExceptionMessage('permission_definition_invalid:a.b');
        PermissionDefinition::int('a.b', 0, 10, 'sideways');
    }

    /**
     * @return list<string>
     */
    private static function cell(string $cell): array
    {
        return array_map(static fn(string $letter): string => self::VALUES[$letter], str_split($cell, 1) ?: []);
    }
}
