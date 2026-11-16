<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

/**
 * Public release-list transport. Discovery is explicitly invoked by a job/admin action, never forum rendering.
 */
interface ReleaseHttpClient
{
    public function fetch(ReleaseRepository $repository, ?string $etag): ReleaseHttpResponse;
}
