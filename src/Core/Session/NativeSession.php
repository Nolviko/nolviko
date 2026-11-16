<?php

declare(strict_types=1);

namespace Nolviko\Core\Session;

use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHP's native session in `<storage>/sessions/` (never the host's shared temporary directory).
 *
 * Shared hosts ignore our php.ini, so every setting is applied with ini_set() right before session_start().
 * PHP does not send the cookie or cache headers itself (`use_cookies=0`, empty `cache_limiter`): the Kernel
 * calls `begin()` before routing and `commit()` on the response, which adds the cookie only when the ID is
 * new and marks the response private. A request without a session cookie that never writes starts nothing;
 * a stale cookie that is only read is cleared instead of being replaced by a new empty session.
 * Strict mode makes PHP replace an ID it did not create, so a forged cookie cannot fix the session ID.
 */
final class NativeSession implements Session
{
    public const COOKIE = 'forum_sid';

    public const DIRECTORY = 'sessions';

    /** Idle lifetime in seconds; expired files are removed by PHP's own garbage collection (no cron). */
    public const LIFETIME = 7200;

    private const DIRECTORY_MODE = 0700;

    /** Characters PHP may use in a session ID; anything else is ignored rather than handed to PHP. */
    private const ID_PATTERN = '/^[A-Za-z0-9,-]{22,256}$/D';

    private ?Request $request = null;

    private ?string $cookieId = null;

    private bool $started = false;

    private bool $written = false;

    /**
     * @param string $storage The private storage directory; `sessions/` is created below it when needed.
     */
    public function __construct(
        private readonly string $storage,
    ) {
    }

    /**
     * The ini settings applied before session_start() for this request.
     *
     * The PHP 8.4-deprecated `session.sid_length` and `session.sid_bits_per_character` are left at their defaults.
     *
     * @return array<string, string>
     */
    public static function settings(Request $request, string $savePath): array
    {
        return [
            'session.save_handler' => 'files',
            'session.save_path' => $savePath,
            'session.name' => self::COOKIE,
            'session.serialize_handler' => 'php_serialize',
            'session.use_strict_mode' => '1',
            'session.use_cookies' => '0',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
            'session.cookie_httponly' => '1',
            'session.cookie_samesite' => Cookie::SAMESITE_LAX,
            'session.cookie_secure' => $request->isSecure() ? '1' : '0',
            'session.cookie_path' => self::cookiePath($request),
            'session.cookie_lifetime' => '0',
            'session.cache_limiter' => '',
            'session.lazy_write' => '1',
            'session.gc_probability' => '1',
            'session.gc_divisor' => '100',
            'session.gc_maxlifetime' => (string) self::LIFETIME,
        ];
    }

    /**
     * Binds the session to the current request; starts nothing.
     */
    public function begin(Request $request): void
    {
        $id = $request->cookies->get(self::COOKIE);
        $this->request = $request;
        $this->cookieId = is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
        $this->started = false;
        $this->written = false;
    }

    /**
     * Writes and closes a started session and adds the cookie when the ID changed; otherwise returns the
     * response untouched.
     */
    public function commit(Response $response): Response
    {
        if (!$this->started || $this->request === null) {
            return $response;
        }

        $id = session_id();
        $this->started = false;

        // A stale or forged cookie that was only read: drop the replacement session instead of issuing it.
        if (!$this->written && $_SESSION === [] && $id !== $this->cookieId) {
            session_destroy();
            $response->headers->clearCookie(
                self::COOKIE,
                self::cookiePath($this->request),
                null,
                $this->request->isSecure(),
                true,
                Cookie::SAMESITE_LAX,
            );
            $this->cookieId = null;

            return $response;
        }

        if (!session_write_close()) {
            throw new RuntimeException('session_write_failed');
        }

        if ($id !== $this->cookieId) {
            $response->headers->setCookie(Cookie::create(
                self::COOKIE,
                $id,
                0,
                self::cookiePath($this->request),
                null,
                $this->request->isSecure(),
                true,
                false,
                Cookie::SAMESITE_LAX,
            ));
            $this->cookieId = $id;
        }

        if (!$response->headers->hasCacheControlDirective('no-store')) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-cache');
        }

        return $response;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->started && $this->cookieId === null) {
            return $default;
        }

        $this->start();

        return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
        $this->written = true;
    }

    public function remove(string $key): void
    {
        if ($this->started || $this->cookieId !== null) {
            $this->start();
            unset($_SESSION[$key]);
            $this->written = true;
        }
    }

    public function regenerate(): void
    {
        $this->start();

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('session_regenerate_failed');
        }

        $this->written = true;
    }

    public function invalidate(): void
    {
        $this->start();
        $_SESSION = [];
        $this->regenerate();
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }

        if ($this->request === null) {
            throw new LogicException('session_not_bound');
        }

        $directory = $this->directory();

        foreach (self::settings($this->request, $directory) as $name => $value) {
            if (ini_set($name, $value) === false) {
                throw new RuntimeException('session_setting_rejected:' . $name);
            }
        }

        // An empty ID makes PHP create one; otherwise strict mode accepts it only if its file exists.
        session_id($this->cookieId ?? '');

        if (!session_start()) {
            throw new RuntimeException('session_start_failed');
        }

        $this->started = true;
    }

    private function directory(): string
    {
        $directory = $this->storage . '/' . self::DIRECTORY;

        if (is_dir($directory)) {
            return $directory;
        }

        if (!is_dir($this->storage) || (!@mkdir($directory, self::DIRECTORY_MODE) && !is_dir($directory))) {
            throw new RuntimeException('session_storage_unavailable');
        }

        @chmod($directory, self::DIRECTORY_MODE);

        return $directory;
    }

    private static function cookiePath(Request $request): string
    {
        return $request->getBasePath() === '' ? '/' : $request->getBasePath();
    }
}
