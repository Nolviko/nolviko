<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use Closure;

/**
 * The container's MessageTranslator: builds the real translator on first use, by which time the Kernel has
 * resolved the request locale (LocaleContext). Services such as ErrorResponder can therefore be built before
 * the request is known without freezing the locale.
 */
final class RequestTranslator implements MessageTranslator
{
    private ?MessageTranslator $translator = null;

    /**
     * @param Closure(): MessageTranslator $create
     */
    public function __construct(
        private readonly Closure $create,
    ) {
    }

    public function translate(string $id, array $parameters = []): string
    {
        return $this->translator()->translate($id, $parameters);
    }

    public function has(string $id): bool
    {
        return $this->translator()->has($id);
    }

    public function locale(): string
    {
        return $this->translator()->locale();
    }

    private function translator(): MessageTranslator
    {
        return $this->translator ??= ($this->create)();
    }
}
