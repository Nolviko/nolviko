<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use Symfony\Component\Translation\Translator;

/**
 * MessageTranslator backed by symfony/translation with ICU formatting; created by TranslatorFactory.
 */
final class SymfonyMessageTranslator implements MessageTranslator
{
    private const ID_PATTERN = '/^([a-z][a-z0-9_]*)\.[A-Za-z0-9_.]+$/D';

    public function __construct(
        private readonly Translator $translator,
    ) {
    }

    public function translate(string $id, array $parameters = []): string
    {
        $domain = self::domainOf($id);

        if (!$this->translator->getCatalogue()->has($id, $domain)) {
            throw MissingMessageException::forId($id);
        }

        return $this->translator->trans($id, $parameters, $domain);
    }

    public function has(string $id): bool
    {
        return $this->translator->getCatalogue()->has($id, self::domainOf($id));
    }

    public function locale(): string
    {
        return $this->translator->getLocale();
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
}
