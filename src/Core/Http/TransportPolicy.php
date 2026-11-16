<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a request may run actions that used to demand HTTPS (owner decision 2026-11-09): only when the owner
 * turned on `http.require_https` must the connection be secure; otherwise plain HTTP works too, so a forum on a
 * host without a certificate can still be administered. HttpsGuard records the setting on every request.
 */
final class TransportPolicy
{
    public const ATTRIBUTE = '_https_optional';

    public static function allows(Request $request): bool
    {
        return $request->isSecure() || $request->attributes->get(self::ATTRIBUTE, true) === true;
    }
}
