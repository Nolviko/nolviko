<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/**
 * The search-engine metadata of the page being rendered, as extension filters see and return it (2026-11-15,
 * first used by the SEO extension). `type` and `key` name the page: type home|forum|thread|tag|gallery|album|image|
 * other and key e.g. `thread:12`, `forum:3`, `tag:php`, `home` (null for pages without an own key). Core escapes
 * every value when printing it and accepts only http(s) addresses for the image and the canonical address.
 */
final readonly class PageMeta
{
    /** @param array<string, string> $context Extra values for templates, e.g. page number. */
    public function __construct(
        public string $type,
        public ?string $key,
        public string $title,
        public string $description,
        public string $keywords = '',
        public ?string $image = null,
        public ?string $canonical = null,
        public bool $noindex = false,
        public array $context = [],
    ) {
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        $values = get_object_vars($this);
        foreach ($changes as $name => $value) {
            if (!array_key_exists($name, $values) || $name === 'type' || $name === 'key') {
                throw new ExtensionFailure('extension_page_meta_invalid');
            }
            $values[$name] = $value;
        }

        return new self(...$values);
    }
}
