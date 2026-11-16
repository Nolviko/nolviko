<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Closure;
use Nolviko\Core\Cache\Cache;
use Nolviko\Core\Session\NativeSession;
use Nolviko\Modules\Users\Http\AuthSession;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/** Five-minute member sampling; guest requests only increment an aggregate cache bucket, never an identity. */
final class CommunityActivity
{
    public function __construct(
        private readonly Closure $profiles,
        private readonly Closure $subjects,
        private readonly Cache $cache,
    ) {
    }

    public function record(Request $request): void
    {
        try {
            $subjects = null;
            $id = null;

            if (
                $request->cookies->has(NativeSession::COOKIE)
                || $request->cookies->has(AuthSession::REMEMBER_COOKIE)
            ) {
                $subjects = ($this->subjects)();
                $id = $subjects->userId($request);
            }

            if ($id === null) {
                $key = 'guest.' . intdiv(time(), 900);
                $count = (int) $this->cache->get($key);
                $this->cache->set($key, min(1000000, $count + 1), 1800);

                return;
            }

            $key = 'member.' . $id;

            if ($this->cache->get($key) !== null) {
                return;
            }

            ($this->profiles)()->touch($subjects->of($request));
            $this->cache->set($key, true, 300);
        } catch (Throwable) {
            // Approximate presence must never interrupt a response, including a pre-upgrade schema.
        }
    }

    public function guestRequests(): int
    {
        return (int) $this->cache->get('guest.' . intdiv(time(), 900));
    }
}
