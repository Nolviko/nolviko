<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

/**
 * Application-owned translation contract (ADR-008). Callers never depend on Symfony catalogue objects.
 *
 * Message IDs are namespaced by domain: the first segment is the catalog file, so
 * `core.error.server.title` lives in `translations/<locale>/core.php`. Messages use ICU MessageFormat.
 */
interface MessageTranslator
{
    /**
     * Translate a message ID with named ICU parameters.
     *
     * @param array<string, scalar|\Stringable|null> $parameters
     *
     * @throws MissingMessageException When the ID exists neither in the active locale nor in English.
     */
    public function translate(string $id, array $parameters = []): string;

    /**
     * Whether the ID exists in the active locale or the English fallback.
     */
    public function has(string $id): bool;

    /**
     * The locale actually used, after validation and fallback (for example `en` when `tr` has no catalog).
     */
    public function locale(): string;
}
