<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Modules\Users\Domain\RememberTokens;
use Nolviko\Modules\Users\Domain\SessionEpochs;
use Nolviko\Modules\Users\Domain\User;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Who is logged in, kept in the session, with an optional remember-me cookie (`forum_remember`).
 *
 * Login regenerates the session ID (session fixation) and rotates the CSRF secret; logout invalidates the
 * session and revokes the presented remember token, or all of the user's tokens ("everywhere"). A valid
 * remember cookie on a request without a logged-in session logs the user in again, with a new session ID and
 * a rotated token.
 *
 * With SessionEpochs (M02.05) every login stores the user's session epoch; a session whose epoch no longer
 * matches (the password was reset since) or whose user is gone is treated as logged out, once per request.
 */
final class AuthSession
{
    public const REMEMBER_COOKIE = 'forum_remember';

    /** The administrator confirmation (AdminSession); cleared on every login so it never passes to another user. */
    public const ELEVATION_KEY = '_admin_reauth_at';

    private const USER_KEY = '_user_id';

    private const EPOCH_KEY = '_user_epoch';

    private ?int $checked = null;

    public function __construct(
        private readonly Session $session,
        private readonly CsrfTokens $csrf,
        private readonly RememberTokens $remember,
        private readonly ResponseCookies $cookies,
        private readonly ?SessionEpochs $epochs = null,
    ) {
    }

    public function login(User $user, bool $remember, Request $request): void
    {
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->remove(self::ELEVATION_KEY);
        $this->session->set(self::USER_KEY, $user->id);
        $this->rememberEpoch($user->id);

        if ($remember) {
            $this->cookies->set($this->rememberCookie($this->remember->issue($user->id), $request));
        }
    }

    public function logout(Request $request, bool $everywhere = false): void
    {
        // Not userId(): that could consume the remember token and issue a new one right before logging out.
        $userId = $this->session->get(self::USER_KEY);
        $cookie = $request->cookies->get(self::REMEMBER_COOKIE);

        if (is_string($cookie)) {
            $this->remember->revoke($cookie);
        }

        if ($everywhere && is_int($userId)) {
            $this->remember->revokeAll($userId);
        }

        $this->session->invalidate();
        $this->cookies->set($this->rememberCookie('', $request, expired: true));
    }

    /**
     * The logged-in user's ID; may log in from a remember-me cookie.
     */
    public function userId(Request $request): ?int
    {
        $userId = $this->session->get(self::USER_KEY);

        if (is_int($userId)) {
            if ($this->epochs === null || $this->checked === $userId) {
                return $userId;
            }

            $epoch = $this->epochs->current($userId);

            if ($epoch === null || $epoch !== $this->session->get(self::EPOCH_KEY)) {
                $this->session->remove(self::USER_KEY);
                $this->session->remove(self::EPOCH_KEY);

                return null;
            }

            return $this->checked = $userId;
        }

        $cookie = $request->cookies->get(self::REMEMBER_COOKIE);

        if (!is_string($cookie) || $cookie === '') {
            return null;
        }

        $used = $this->remember->consume($cookie);

        if ($used === null) {
            $this->cookies->set($this->rememberCookie('', $request, expired: true));

            return null;
        }

        [$userId, $replacement] = $used;
        if ($this->epochs !== null && $this->epochs->current($userId) === null) {
            $this->remember->revoke($replacement);
            $this->cookies->set($this->rememberCookie('', $request, expired: true));

            return null;
        }

        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->remove(self::ELEVATION_KEY);
        $this->session->set(self::USER_KEY, $userId);
        $this->rememberEpoch($userId);
        $this->cookies->set($this->rememberCookie($replacement, $request));

        return $userId;
    }

    private function rememberEpoch(int $userId): void
    {
        if ($this->epochs !== null) {
            $this->session->set(self::EPOCH_KEY, $this->epochs->current($userId));
            $this->checked = $userId;
        }
    }

    private function rememberCookie(string $value, Request $request, bool $expired = false): Cookie
    {
        return Cookie::create(
            self::REMEMBER_COOKIE,
            $value,
            $expired ? 1 : time() + RememberTokens::LIFETIME_DAYS * 86400,
            $request->getBasePath() === '' ? '/' : $request->getBasePath(),
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
