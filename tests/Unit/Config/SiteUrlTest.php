<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Config;

use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Config\ConfigSchema;
use Nolviko\Tests\Support\SiteFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M02.03: `app.url`, the site's own address for links in mail, is an absolute http(s) base without credentials,
 * query or fragment; it is stored without a trailing slash.
 */
final class SiteUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'https host' => ['https://forum.example.com', true];
        yield 'port and folder' => ['http://example.com:8080/community/', true];
        yield 'relative' => ['/forum', false];
        yield 'other scheme' => ['javascript:alert(1)', false];
        yield 'credentials' => ['https://user:pass@example.com', false];
        yield 'query' => ['https://example.com/?next=evil', false];
        yield 'fragment' => ['https://example.com/#x', false];
        yield 'space' => ['https://exa mple.com', false];
        yield 'backslash' => ['https://example.com\\evil.example', false];
    }

    #[DataProvider('urls')]
    public function testOnlyAbsoluteBaseUrlsAreAccepted(string $url, bool $valid): void
    {
        self::assertSame($valid, ConfigSchema::isBaseUrl($url));
    }

    public function testLoadedWithoutTrailingSlashAndRefusedWhenInvalid(): void
    {
        $config = ConfigLoader::fromArray([...SiteFixture::validConfig(), 'app' => [
            ...SiteFixture::validConfig()['app'],
            'url' => 'https://forum.example.com/',
        ]]);

        self::assertSame('https://forum.example.com', $config->appUrl);
        self::assertNull(ConfigLoader::fromArray(SiteFixture::validConfig())->appUrl);

        $this->expectException(ConfigException::class);
        ConfigLoader::fromArray(SiteFixture::validConfig(), ['FORUM_APP_URL' => 'ftp://example.com']);
    }
}
