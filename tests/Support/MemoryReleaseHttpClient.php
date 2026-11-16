<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Core\Updates\ReleaseHttpClient;
use Nolviko\Core\Updates\ReleaseHttpResponse;
use Nolviko\Core\Updates\ReleaseRepository;
use RuntimeException;
use Throwable;

/**
 * Deterministic release responses; no unit test needs the network.
 */
final class MemoryReleaseHttpClient implements ReleaseHttpClient
{
    /** @var list<array{url: string, etag: ?string}> */
    public array $requests = [];

    /**
     * @param list<ReleaseHttpResponse|Throwable> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function fetch(ReleaseRepository $repository, ?string $etag): ReleaseHttpResponse
    {
        $this->requests[] = ['url' => $repository->apiUrl(), 'etag' => $etag];
        $response = array_shift($this->responses) ?? throw new RuntimeException('test_release_response_missing');

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
