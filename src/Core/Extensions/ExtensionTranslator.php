<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use MessageFormatter;
use Nolviko\Core\Locale\MessageTranslator;

/** Extension JSON messages are owned by ID; fallback is English and core messages cannot be replaced. */
final readonly class ExtensionTranslator implements MessageTranslator
{
    /** @param array<string, array<string, string>> $messages */
    public function __construct(
        private MessageTranslator $core,
        private array $messages,
    ) {
    }

    public function translate(string $id, array $parameters = []): string
    {
        if ($this->core->has($id)) {
            return $this->core->translate($id, $parameters);
        }

        $message = $this->messages[$this->locale()][$id] ?? $this->messages['en'][$id] ?? null;
        if ($message === null) {
            return $this->core->translate($id, $parameters);
        }

        $formatter = new MessageFormatter($this->locale(), $message);
        $formatted = $formatter->format($parameters);
        if ($formatted === false) {
            throw new ExtensionFailure('extension_translation_invalid');
        }

        return $formatted;
    }

    public function has(string $id): bool
    {
        return $this->core->has($id)
            || isset($this->messages[$this->locale()][$id])
            || isset($this->messages['en'][$id]);
    }

    public function locale(): string
    {
        return $this->core->locale();
    }
}
