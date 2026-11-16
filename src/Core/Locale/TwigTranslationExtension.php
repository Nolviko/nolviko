<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `t(id, parameters)` to Twig. The result is not marked safe, so autoescaping applies to the
 * whole translated string, including hostile parameters. Catalogs therefore never contain HTML.
 */
final class TwigTranslationExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageTranslator $translator,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translator->translate(...)),
        ];
    }
}
