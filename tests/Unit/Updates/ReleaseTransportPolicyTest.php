<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Updates\NativeReleaseHttpClient;
use Nolviko\Core\Updates\ReleaseHttpResponse;
use Nolviko\Core\Updates\ReleaseRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Repository/ETag values cannot introduce another origin or HTTP headers; retry hints are bounded integers.
 */
final class ReleaseTransportPolicyTest extends TestCase
{
    public function testRepositoryIdentityHasOneFixedHttpsOrigin(): void
    {
        $repository = new ReleaseRepository('Example', 'Forum');
        self::assertSame('https://api.github.com/repos/Example/Forum/releases?per_page=100', $repository->apiUrl());
        self::assertSame($repository->key(), (new ReleaseRepository('example', 'forum'))->key());
    }

    #[DataProvider('invalidRepositories')]
    public function testInvalidRepositoryCannotReachTheTransport(string $owner, string $repository): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('github_repository_invalid');
        new ReleaseRepository($owner, $repository);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidRepositories(): array
    {
        return [
            'host injection' => ['https://attacker.invalid', 'forum'],
            'owner traversal' => ['../owner', 'forum'],
            'repository traversal' => ['owner', '../forum'],
            'repository parent' => ['owner', '..'],
            'repository dot' => ['owner', '.'],
            'query injection' => ['owner', 'forum?per_page=1'],
            'header injection' => ['owner' . chr(13) . chr(10) . 'X-Evil', 'forum'],
            'owner length' => [str_repeat('a', 40), 'forum'],
            'repository length' => ['owner', str_repeat('a', 101)],
        ];
    }

    #[DataProvider('etags')]
    public function testOnlySafeEtagValidatorsCanBeReused(?string $input, ?string $expected): void
    {
        self::assertSame($expected, NativeReleaseHttpClient::safeEtag($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function etags(): array
    {
        return [
            'strong validator' => ['"abc-123"', '"abc-123"'],
            'weak validator' => ['W/"abc-123"', 'W/"abc-123"'],
            'absent' => [null, null],
            'unquoted' => ['abc', null],
            'newline' => ['"abc"' . chr(13) . chr(10) . 'X-Evil: value', null],
            'backslash' => ['"a' . chr(92) . 'b"', null],
            'oversized' => ['"' . str_repeat('a', 161) . '"', null],
        ];
    }

    public function testNegativeRetryHintIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('github_response_retry_invalid');
        new ReleaseHttpResponse(429, '', retryAfter: -1);
    }

    public function testExcessiveRetryHintIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('github_response_retry_invalid');
        new ReleaseHttpResponse(429, '', rateLimitReset: ReleaseHttpResponse::MAX_RETRY_HINT + 1);
    }
}
