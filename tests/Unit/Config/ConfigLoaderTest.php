<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Config;

use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Logging\IpPolicy;
use Nolviko\Tests\Support\SiteFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private SiteFixture $site;

    protected function setUp(): void
    {
        $this->site = SiteFixture::notConfigured();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testMissingFileMeansNotConfigured(): void
    {
        self::assertNull(ConfigLoader::load($this->site->configDirectory . '/local.php', []));
    }

    public function testValidFileIsTyped(): void
    {
        $config = ConfigLoader::load($this->site->writeConfig(SiteFixture::validConfig()), []);

        self::assertNotNull($config);
        self::assertSame('mysql', $config->databaseDriver);
        self::assertSame(3306, $config->databasePort);
        self::assertSame(SiteFixture::DB_PASSWORD, $config->databasePassword->reveal());
        self::assertSame(SiteFixture::APP_SECRET, $config->appSecret->reveal());
    }

    public function testIpLoggingDefaultsToTruncatedAndCanBeChanged(): void
    {
        $file = $this->site->writeConfig(SiteFixture::validConfig());
        $values = [...SiteFixture::validConfig(), 'privacy' => ['ip_logging' => 'none']];

        self::assertSame(IpPolicy::Truncated, ConfigLoader::load($file, [])?->ipLogging);
        self::assertSame(IpPolicy::Full, ConfigLoader::load($file, ['FORUM_IP_LOGGING' => 'full'])?->ipLogging);
        self::assertSame(IpPolicy::None, ConfigLoader::load($this->site->writeConfig($values), [])?->ipLogging);
    }

    public function testHttpSettingsDefaultOffAndAcceptFileOrEnvironmentValues(): void
    {
        $file = $this->site->writeConfig(SiteFixture::validConfig());
        $defaults = ConfigLoader::load($file, []);

        self::assertSame([[], false, false], [$defaults?->trustedProxies, $defaults?->hsts, $defaults?->requireHttps]);

        $fromEnvironment = ConfigLoader::load($file, [
            'FORUM_TRUSTED_PROXIES' => ' 10.0.0.0/8, 2001:db8::/32 ,192.0.2.7',
            'FORUM_HSTS' => 'Yes',
            'FORUM_REQUIRE_HTTPS' => '1',
        ]);

        self::assertSame(['10.0.0.0/8', '2001:db8::/32', '192.0.2.7'], $fromEnvironment?->trustedProxies);
        self::assertTrue($fromEnvironment?->hsts);
        self::assertTrue($fromEnvironment?->requireHttps);
        self::assertFalse(ConfigLoader::load($file, ['FORUM_HSTS' => 'off', 'FORUM_REQUIRE_HTTPS' => '0'])?->hsts);

        $values = [...SiteFixture::validConfig(), 'http' => ['trusted_proxies' => ['::1'], 'require_https' => true]];
        $fromFile = ConfigLoader::load($this->site->writeConfig($values), []);

        self::assertSame(['::1'], $fromFile?->trustedProxies);
        self::assertTrue($fromFile?->requireHttps);
    }

    public function testPortIsOptional(): void
    {
        $values = SiteFixture::validConfig();
        unset($values['database']['port']);

        self::assertNull(ConfigLoader::load($this->site->writeConfig($values), [])?->databasePort);
    }

    public function testThreadsPerPageIsOptionalAndBounded(): void
    {
        $values = SiteFixture::validConfig();
        $file = $this->site->writeConfig($values);

        self::assertNull(ConfigLoader::load($file, [])?->threadsPerPage);
        self::assertSame(50, ConfigLoader::load($file, ['FORUM_THREADS_PER_PAGE' => '50'])?->threadsPerPage);
        self::assertSame(
            10,
            ConfigLoader::load($this->site->writeConfig([...$values, 'threads' => ['per_page' => 10]]), [])
                ?->threadsPerPage,
        );
    }

    public function testPostingRulesDefaultAndOverride(): void
    {
        $values = SiteFixture::validConfig();
        $defaults = ConfigLoader::load($this->site->writeConfig($values), [])?->postingRules;
        $custom = ConfigLoader::load($this->site->writeConfig([...$values, 'antispam' => [
            'new_user_max_links' => 2,
            'blocked_domains' => ['Spam.Example'],
        ]]), ['FORUM_BLOCKED_WORDS' => 'casino, pills'])?->postingRules;

        self::assertSame([1, 3, 24, 0, [], []], [
            $defaults?->firstPostsModerated,
            $defaults?->linkMinPosts,
            $defaults?->linkMinAgeHours,
            $defaults?->newUserMaxLinks,
            $defaults?->blockedWords,
            $defaults?->blockedDomains,
        ]);
        self::assertSame([2, ['casino', 'pills'], ['spam.example']], [
            $custom?->newUserMaxLinks,
            $custom?->blockedWords,
            $custom?->blockedDomains,
        ]);
    }

    public function testEnvironmentOverridesFileValues(): void
    {
        $config = ConfigLoader::load($this->site->writeConfig(SiteFixture::validConfig()), [
            'DB_DRIVER' => 'mariadb',
            'DB_HOST' => 'db',
            'DB_PORT' => '3307',
            'DB_PASSWORD' => 'from-env',
            'FORUM_APP_SECRET' => str_repeat('e', 40),
            'DB_DATABASE' => '',
        ]);

        self::assertNotNull($config);
        self::assertSame('mariadb', $config->databaseDriver);
        self::assertSame('db', $config->databaseHost);
        self::assertSame(3307, $config->databasePort);
        self::assertSame('forum', $config->databaseName, 'empty variables do not override');
        self::assertSame('from-env', $config->databasePassword->reveal());
        self::assertSame(str_repeat('e', 40), $config->appSecret->reveal());
    }

    /**
     * @return list<array{0: array<mixed>, 1: array<string, string>, 2: list<string>}>
     */
    public static function invalidConfigs(): array
    {
        $valid = SiteFixture::validConfig();
        $with = static fn(string $section, string $key, mixed $value): array
            => array_replace_recursive($valid, [$section => [$key => $value]]);
        $without = static function (string $section, string $key) use ($valid): array {
            unset($valid[$section][$key]);

            return $valid;
        };

        return [
            [[...$valid, 'debug' => true], [], ['config_key_unknown:debug']],
            [$with('database', 'socket', '/tmp/x'), [], ['config_key_unknown:database.socket']],
            [$with('database', 'port', '3306'), [], ['config_type_invalid:database.port']],
            [$with('database', 'password', 12345), [], ['config_type_invalid:database.password']],
            [[...$valid, 'database' => 'mysql://u:p@h/db'], [], ['config_type_invalid:database',
                'config_key_missing:database.driver', 'config_key_missing:database.host',
                'config_key_missing:database.name', 'config_key_missing:database.user',
                'config_key_missing:database.password']],
            [$without('app', 'secret'), [], ['config_key_missing:app.secret']],
            [$with('database', 'driver', 'sqlite'), [], ['config_value_invalid:database.driver']],
            [$with('database', 'port', 70000), [], ['config_value_invalid:database.port']],
            [$with('database', 'host', ' '), [], ['config_value_invalid:database.host']],
            [$with('app', 'secret', 'short'), [], ['config_value_invalid:app.secret']],
            [$valid, ['DB_PORT' => 'abc'], ['config_type_invalid:database.port']],
            [$valid, ['DB_DRIVER' => 'oracle'], ['config_value_invalid:database.driver']],
            [[...$valid, 'privacy' => ['ip_logging' => 'partial']], [], ['config_value_invalid:privacy.ip_logging']],
            [$valid, ['FORUM_IP_LOGGING' => 'everything'], ['config_value_invalid:privacy.ip_logging']],
            [[...$valid, 'http' => ['hsts' => 'yes']], [], ['config_type_invalid:http.hsts']],
            [[...$valid, 'http' => ['require_https' => 1]], [], ['config_type_invalid:http.require_https']],
            [[...$valid, 'http' => ['trusted_proxies' => '10.0.0.1']], [],
                ['config_type_invalid:http.trusted_proxies']],
            [[...$valid, 'http' => ['trusted_proxies' => ['a' => '10.0.0.1']]], [],
                ['config_key_unknown:http.trusted_proxies.a']],
            [[...$valid, 'http' => ['proxies' => []]], [], ['config_key_unknown:http.proxies']],
            [$valid, ['FORUM_HSTS' => 'maybe'], ['config_type_invalid:http.hsts']],
            [$valid, ['FORUM_TRUSTED_PROXIES' => '10.0.0.0/33'], ['config_value_invalid:http.trusted_proxies']],
            [$valid, ['FORUM_TRUSTED_PROXIES' => 'proxy.local'], ['config_value_invalid:http.trusted_proxies']],
            [$valid, ['FORUM_TRUSTED_PROXIES' => 'REMOTE_ADDR'], ['config_value_invalid:http.trusted_proxies']],
            [[...$valid, 'threads' => ['per_page' => 9]], [], ['config_value_invalid:threads.per_page']],
            [$valid, ['FORUM_THREADS_PER_PAGE' => '51'], ['config_value_invalid:threads.per_page']],
            [[...$valid, 'threads' => ['per_page' => '20']], [], ['config_type_invalid:threads.per_page']],
            [$valid, ['FORUM_POSTING_IP_INTERVAL' => '3601'], ['config_value_invalid:posting.ip_interval_seconds']],
            [[...$valid, 'posting' => ['first_posts_moderated' => 0]], [],
                ['config_value_invalid:posting.first_posts_moderated']],
            [$valid, ['FORUM_NEW_USER_MAX_LINKS' => '51'], ['config_value_invalid:antispam.new_user_max_links']],
            [$valid, ['FORUM_BLOCKED_DOMAINS' => 'spam.example,bad domain'],
                ['config_value_invalid:antispam.blocked_domains']],
            [[...$valid, 'antispam' => ['blocked_words' => [' ']]], [],
                ['config_value_invalid:antispam.blocked_words']],
            [[...$valid, 'posting' => ['ip_interval_seconds' => -1]], [],
                ['config_value_invalid:posting.ip_interval_seconds']],
        ];
    }

    /**
     * @param array<mixed> $values
     * @param array<string, string> $environment
     * @param list<string> $expected
     */
    #[DataProvider('invalidConfigs')]
    public function testSchemaViolationsFailWithDiagnosticIds(array $values, array $environment, array $expected): void
    {
        try {
            ConfigLoader::load($this->site->writeConfig($values), $environment);
            self::fail('expected a configuration error');
        } catch (ConfigException $exception) {
            self::assertEqualsCanonicalizing($expected, $exception->diagnostics());
            self::assertStringNotContainsString(SiteFixture::DB_PASSWORD, $exception->getMessage());
        }
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unreadableFiles(): array
    {
        return [
            ['<?php return "' . SiteFixture::DB_PASSWORD . '";'],
            ['<?php return [' . SiteFixture::DB_PASSWORD],
            ['<?php throw new RuntimeException("' . SiteFixture::DB_PASSWORD . '");'],
        ];
    }

    #[DataProvider('unreadableFiles')]
    public function testUnreadableFileFailsWithoutQuotingIt(string $source): void
    {
        try {
            ConfigLoader::load($this->site->writeConfig($source), []);
            self::fail('expected a configuration error');
        } catch (ConfigException $exception) {
            self::assertSame(['config_file_invalid'], $exception->diagnostics());
            self::assertStringNotContainsString(SiteFixture::DB_PASSWORD, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
