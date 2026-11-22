<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Conversations;

use Nolviko\Modules\Conversations\Domain\ConversationRefused;
use Nolviko\Modules\Conversations\Domain\RecipientList;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use PHPUnit\Framework\TestCase;

/** Beta 17 G1: recipient lists of a private message and formatted message rendering. */
final class RecipientListTest extends TestCase
{
    public function testNamesAreSplitTrimmedAndDeduplicated(): void
    {
        $names = RecipientList::parse(" Alice, bob;\nALICE ,, Carol ");

        self::assertSame(['Alice', 'bob', 'Carol'], array_values($names));
    }

    public function testVisibleAndHiddenAreKeptApart(): void
    {
        $lists = RecipientList::both('alice, bob', 'carol', 10);

        self::assertSame(['alice', 'bob'], array_values($lists['visible']));
        self::assertSame(['carol'], array_values($lists['hidden']));
    }

    public function testHiddenRecipientsCountTowardsTheLimit(): void
    {
        try {
            RecipientList::both('a1, a2', 'a3', 2);
            self::fail('limit not applied');
        } catch (ConversationRefused $refused) {
            self::assertSame('conversation_recipients_limit', $refused->diagnostic);
            self::assertSame(['limit' => 2], $refused->parameters);
        }
    }

    public function testNameInBothListsIsRefused(): void
    {
        $this->expectException(ConversationRefused::class);
        $this->expectExceptionMessage('conversation_recipient_twice');

        RecipientList::both('alice', 'ALICE', 10);
    }

    public function testEmptyListsAreRefused(): void
    {
        $this->expectExceptionMessage('conversation_recipient_required');

        RecipientList::both(' , ', '', 10);
    }

    public function testOversizedOrControlCharacterFieldsAreRefused(): void
    {
        foreach ([str_repeat('a,', 600), "bad\x01name", "\xff"] as $field) {
            try {
                RecipientList::parse($field);
                self::fail('accepted: ' . bin2hex($field));
            } catch (ConversationRefused $refused) {
                self::assertSame('conversation_recipient_unknown', $refused->diagnostic);
            }
        }
    }

    public function testPlainMessagesRenderUnchangedAndFormattingIsSanitized(): void
    {
        $renderer = new BbcodeRenderer();

        self::assertStringContainsString('&lt;script&gt;plain', $renderer->render('<script>plain'));
        self::assertStringNotContainsString('<script', $renderer->render('[b]<script>x</script>[/b]'));
        self::assertStringContainsString('<strong>', $renderer->render('[b]bold[/b]'));
    }
}
