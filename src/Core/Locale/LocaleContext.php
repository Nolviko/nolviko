<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use Closure;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * The locale and time zone of the current request, resolved once by the Kernel before routing (after the
 * session is bound, because the user's preferences may need it). Until then, and when nothing matches, they
 * are `en` and `UTC`.
 *
 * Locale: user preference → site default → Accept-Language → en (LocaleResolver). Time zone: user preference →
 * site default → UTC; only valid IANA names count. The sources are closures so that they are only consulted
 * when needed; a source that fails (database unavailable, not installed yet) counts as "no preference".
 */
final class LocaleContext
{
    public const DEFAULT_TIME_ZONE = 'UTC';

    private string $locale = TranslatorFactory::FALLBACK_LOCALE;

    private string $timeZone = self::DEFAULT_TIME_ZONE;

    /**
     * @param Closure(Request): (array{locale: ?string, time_zone: ?string}|null) $user The logged-in user's
     *     stored preferences.
     * @param Closure(): ?string $siteLocale `locale.default` from the configuration.
     * @param (Closure(): ?string)|null $siteTimeZone `locale.time_zone` from the configuration.
     */
    public function __construct(
        private readonly LocaleResolver $resolver,
        private readonly Closure $user,
        private readonly Closure $siteLocale,
        private readonly ?Closure $siteTimeZone = null,
    ) {
    }

    public function resolve(Request $request): string
    {
        $user = self::attempt(fn(): ?array => ($this->user)($request));
        $this->locale = $this->resolver->resolve(
            $user['locale'] ?? null,
            self::attempt($this->siteLocale),
            $request->headers->get('Accept-Language'),
        );
        $siteTimeZone = $this->siteTimeZone === null ? null : self::attempt($this->siteTimeZone);
        $candidates = [$user['time_zone'] ?? null, $siteTimeZone];
        $this->timeZone = self::DEFAULT_TIME_ZONE;

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && LocaleFormatter::isTimeZone($candidate)) {
                $this->timeZone = $candidate;

                break;
            }
        }

        return $this->locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function timeZone(): string
    {
        return $this->timeZone;
    }

    /**
     * @template T
     *
     * @param Closure(): T $source
     *
     * @return T|null
     */
    private static function attempt(Closure $source): mixed
    {
        try {
            return $source();
        } catch (Throwable) {
            return null;
        }
    }
}
