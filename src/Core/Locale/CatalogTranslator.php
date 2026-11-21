<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use Symfony\Component\Translation\Formatter\MessageFormatter;

/**
 * MessageTranslator over `translations/<locale>/<domain>.php` that loads a domain only when one of its IDs is used
 * (2026-11-21 benchmark: building the full Symfony catalogue, plus the English fallback, on every request was up to
 * 11 % of a page's CPU). Catalog files return flat arrays and are served from OPcache, so a lookup is an include
 * and an array read. Formatting is Symfony's ICU formatter, with the locale of the catalog that defined the message,
 * exactly as Symfony's Translator does.
 */
final class CatalogTranslator implements MessageTranslator
{
    private const ID_PATTERN = '/^([a-z][a-z0-9_]*)\.[A-Za-z0-9_.]+$/D';

    /** @var array<string, array<string, array<string, string>>> Locale => domain => messages. */
    private array $loaded = [];

    private readonly MessageFormatter $formatter;

    /**
     * @param array<string, array<string, string>> $files Locale => domain => catalog file (active locale and `en`).
     */
    public function __construct(
        private readonly string $locale,
        private readonly array $files,
    ) {
        $this->formatter = new MessageFormatter();
    }

    public function translate(string $id, array $parameters = []): string
    {
        $domain = self::domainOf($id);

        foreach ($this->chain() as $locale) {
            $message = $this->messages($locale, $domain)[$id] ?? null;

            if (is_string($message)) {
                return $this->formatter->formatIntl($message, $locale, $parameters);
            }
        }

        throw MissingMessageException::forId($id);
    }

    public function has(string $id): bool
    {
        $domain = self::domainOf($id);

        foreach ($this->chain() as $locale) {
            if (is_string($this->messages($locale, $domain)[$id] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * The domain is the first ID segment; IDs without a domain prefix are rejected.
     */
    public static function domainOf(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id, $match) !== 1) {
            throw MissingMessageException::invalidId($id);
        }

        return $match[1];
    }

    /** @return list<string> */
    private function chain(): array
    {
        return $this->locale === TranslatorFactory::FALLBACK_LOCALE
            ? [$this->locale] : [$this->locale, TranslatorFactory::FALLBACK_LOCALE];
    }

    /** @return array<string, string> */
    private function messages(string $locale, string $domain): array
    {
        if (!isset($this->loaded[$locale][$domain])) {
            $file = $this->files[$locale][$domain] ?? null;
            $messages = $file === null ? [] : self::read($file);
            $this->loaded[$locale][$domain] = is_array($messages) ? $messages : [];
        }

        return $this->loaded[$locale][$domain];
    }

    private static function read(string $file): mixed
    {
        return require $file;
    }
}
