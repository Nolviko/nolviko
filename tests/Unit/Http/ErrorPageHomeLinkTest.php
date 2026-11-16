<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Locale\TranslatorFactory;
use PHPUnit\Framework\TestCase;

/**
 * Error pages lead back (owner request 2026-11-14): the logo and a link under the text go to the home page.
 */
final class ErrorPageHomeLinkTest extends TestCase
{
    public function testLogoAndLinkLeadHome(): void
    {
        $translator = TranslatorFactory::create(dirname(__DIR__, 3) . '/translations', 'tr');
        $html = (string) (new ErrorResponder($translator, static fn(): string => '/forum/'))->forbidden()->getContent();

        self::assertStringContainsString('<a class="brand" href="/forum/"', $html);
        self::assertStringContainsString('<a class="home" href="/forum/">Ana sayfaya dön</a>', $html);
    }

    public function testWithoutARouterTheHomeIsGuessedFromTheScript(): void
    {
        $saved = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/site/public/index.php';

        try {
            $html = (string) (new ErrorResponder(null))->notFound()->getContent();
        } finally {
            $_SERVER['SCRIPT_NAME'] = $saved;
        }

        self::assertStringContainsString('href="/site/"', $html);
    }
}
