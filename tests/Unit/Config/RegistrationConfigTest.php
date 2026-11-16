<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Config;

use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Tests\Support\SiteFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M02.03a: registration settings default to email confirmation, a 3 s minimum fill time, no question and one
 * account per network every 120 s; values outside their ranges are refused at boot.
 */
final class RegistrationConfigTest extends TestCase
{
    public function testDefaultsAndOverrides(): void
    {
        $defaults = ConfigLoader::fromArray(SiteFixture::validConfig())->registration;
        $custom = ConfigLoader::fromArray([...SiteFixture::validConfig(), 'registration' => [
            'mode' => 'approval',
            'min_seconds' => 0,
            'ip_interval_seconds' => 0,
            'challenge' => 'question',
        ]])->registration;
        $environment = ConfigLoader::fromArray(SiteFixture::validConfig(), [
            'FORUM_REGISTRATION_MODE' => 'closed',
        ])->registration;

        self::assertSame(
            [RegistrationRules::VERIFY, 3, 120, RegistrationRules::CHALLENGE_NONE],
            [$defaults->mode, $defaults->minSeconds, $defaults->networkSeconds, $defaults->challenge],
        );
        self::assertSame(
            [RegistrationRules::APPROVAL, 0, 0, RegistrationRules::CHALLENGE_QUESTION],
            [$custom->mode, $custom->minSeconds, $custom->networkSeconds, $custom->challenge],
        );
        self::assertSame(RegistrationRules::CLOSED, $environment->mode);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'mode' => [['mode' => 'invite'], 'registration.mode'];
        yield 'challenge' => [['challenge' => 'recaptcha'], 'registration.challenge'];
        yield 'min seconds' => [['min_seconds' => 61], 'registration.min_seconds'];
        yield 'network seconds' => [['ip_interval_seconds' => -1], 'registration.ip_interval_seconds'];
    }

    /**
     * @param array<string, mixed> $registration
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(array $registration, string $key): void
    {
        try {
            ConfigLoader::fromArray([...SiteFixture::validConfig(), 'registration' => $registration]);
            self::fail('expected a config error');
        } catch (ConfigException $exception) {
            self::assertContains('config_value_invalid:' . $key, $exception->diagnostics());
        }
    }
}
