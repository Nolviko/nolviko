<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Locale;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Locale\LocaleContext;
use Nolviko\Core\Locale\LocaleFormatter;
use Nolviko\Core\Locale\LocaleResolver;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\TemplateException;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Tests\Support\SiteFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * M02.13: ICU plurals, dates in the viewer's time zone (stored UTC, DST-correct) and localized numbers.
 */
final class LocaleFormattingTest extends TestCase
{
    private const PATTERN = 'yyyy-MM-dd HH:mm:ss zzz';

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function dstBoundaries(): array
    {
        return [
            'New York, last second of EST' => ['America/New_York', '2026-03-08 06:59:59', '2026-03-08 01:59:59 EST'],
            'New York, the hour 02:xx does not exist' => [
                'America/New_York',
                '2026-03-08 07:00:00',
                '2026-03-08 03:00:00 EDT',
            ],
            'Berlin, first 02:30 (summer time)' => ['Europe/Berlin', '2026-10-25 00:30:00', '2026-10-25 02:30:00 MESZ'],
            'Berlin, repeated 02:30 (winter time)' => [
                'Europe/Berlin',
                '2026-10-25 01:30:00',
                '2026-10-25 02:30:00 MEZ',
            ],
            'Istanbul has no DST' => ['Europe/Istanbul', '2026-07-01 09:00:00', '2026-07-01 12:00:00 GMT+3'],
        ];
    }

    #[DataProvider('dstBoundaries')]
    public function testUtcMomentsAreShownInTheViewersZoneAcrossDst(string $zone, string $utc, string $expected): void
    {
        $locale = str_starts_with($zone, 'Europe/Berlin') ? 'de' : 'en';
        $moment = new DateTimeImmutable($utc . ' UTC');

        self::assertSame($expected, (new LocaleFormatter($locale, $zone))->pattern($moment, self::PATTERN));
    }

    public function testStylesFollowTheLocale(): void
    {
        $moment = new DateTimeImmutable('2026-11-01 12:00:00 UTC');
        $turkish = new LocaleFormatter('tr', 'Europe/Istanbul');
        $english = new LocaleFormatter('en', 'UTC');

        self::assertStringContainsString('Kasım', $turkish->date($moment, 'long'));
        self::assertStringContainsString('15:00', $turkish->time($moment));
        self::assertStringContainsString('November', $english->date($moment, 'long'));
        self::assertSame('1.234,5', $turkish->number(1234.5));
        self::assertSame('1,234.50', $english->number(1234.5, 2));
        self::assertSame('-0,25', $turkish->number(-0.25));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('date_style_invalid:huge');
        $english->date($moment, 'huge');
    }

    public function testUnknownTimeZonesAreRefused(): void
    {
        self::assertFalse(LocaleFormatter::isTimeZone('Mars/Olympus'));
        self::assertFalse(LocaleFormatter::isTimeZone('+03:00'));

        $this->expectExceptionMessage('time_zone_invalid');
        new LocaleFormatter('en', 'Mars/Olympus');
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function plurals(): array
    {
        return [
            'en zero' => ['en', 0, 'No replies'],
            'en one' => ['en', 1, '1 reply'],
            'en other' => ['en', 2, '2 replies'],
            'en large number is localized' => ['en', 12345, '12,345 replies'],
            'tr one' => ['tr', 1, '1 yanıt'],
            'tr other' => ['tr', 3, '3 yanıt'],
            'ar two (dual)' => ['ar', 2, 'ردّان'],
            'ar few' => ['ar', 3, '٣ ردود'],
            'ar many' => ['ar', 11, '١١ ردًا'],
        ];
    }

    #[DataProvider('plurals')]
    public function testIcuPluralCategories(string $locale, int $count, string $expected): void
    {
        $catalogs = self::pluralCatalogs();
        $text = TranslatorFactory::create($catalogs->path, $locale)->translate('demo.replies', ['count' => $count]);
        $catalogs->remove();

        self::assertSame($expected, $text);
    }

    public function testTwigFiltersUseTheRequestZoneAndRefuseMutableDates(): void
    {
        $app = dirname(__DIR__, 3);
        $site = new TemporaryDirectory();
        $site->write('themes/default/theme.json', '{"id": "default", "parent": null}');
        $site->write('views/when.twig', "{{ at|format_datetime('none', 'short') }} {{ n|format_number }}");
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $renderer = TwigFactory::create(
            $site->path,
            $site->path . '/no-storage',
            $translator,
            formatter: static fn(): LocaleFormatter => new LocaleFormatter('tr', 'Europe/Istanbul'),
        );

        self::assertSame('12:00 1.000', $renderer->render('when.twig', [
            'at' => new DateTimeImmutable('2026-11-01 09:00:00 UTC'),
            'n' => 1000,
        ]));

        try {
            $renderer->render('when.twig', ['at' => new DateTime('now'), 'n' => 1]);
            self::fail('accepted a mutable DateTime');
        } catch (TemplateException $failure) {
            self::assertSame('view_context_invalid:at', $failure->getMessage());
        } finally {
            $site->remove();
        }
    }

    public function testTimeZoneResolutionOrder(): void
    {
        $context = static fn(?string $user, ?string $site): LocaleContext => new LocaleContext(
            new LocaleResolver(['en']),
            static fn(): array => ['locale' => null, 'time_zone' => $user],
            static fn(): ?string => null,
            static fn(): ?string => $site,
        );
        $request = Request::create('/');

        $cases = [
            ['Europe/Istanbul', 'Asia/Tokyo', 'Europe/Istanbul'],
            [null, 'Asia/Tokyo', 'Asia/Tokyo'],
            ['Not/AZone', 'Asia/Tokyo', 'Asia/Tokyo'],
            [null, null, 'UTC'],
        ];

        foreach ($cases as [$user, $site, $expected]) {
            $resolved = $context($user, $site);
            $resolved->resolve($request);
            self::assertSame($expected, $resolved->timeZone(), (string) $user);
        }
    }

    public function testSiteTimeZoneIsValidatedConfiguration(): void
    {
        $settings = SiteFixture::validConfig();
        $settings['locale'] = ['time_zone' => 'Europe/Istanbul'];

        self::assertSame('Europe/Istanbul', ConfigLoader::fromArray($settings)->defaultTimeZone);

        $settings['locale'] = ['time_zone' => 'Istanbul'];
        $this->expectException(ConfigException::class);
        ConfigLoader::fromArray($settings);
    }

    private static function pluralCatalogs(): TemporaryDirectory
    {
        $catalogs = new TemporaryDirectory();
        $catalogs->writeCatalog('en', 'demo', [
            'demo.replies' => '{count, plural, =0 {No replies} one {# reply} other {# replies}}',
        ]);
        $catalogs->writeCatalog('tr', 'demo', ['demo.replies' => '{count, plural, one {# yanıt} other {# yanıt}}']);
        $catalogs->writeCatalog('ar', 'demo', [
            'demo.replies' => '{count, plural, zero {لا ردود} one {ردّ واحد} two {ردّان} few {# ردود} '
                . 'many {# ردًا} other {# ردّ}}',
        ]);

        return $catalogs;
    }
}
