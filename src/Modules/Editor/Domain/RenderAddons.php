<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

/**
 * The administrator's smileys and MyCode (ADR-042) as the renderer uses them. `version` grows with every change of
 * either set; it is part of the render-cache key (BbcodeRenderer::version()), so cached posts re-render lazily.
 * Smiley HTML is built here from checked values only (image path from the router, sizes as integers).
 */
final readonly class RenderAddons
{
    /** @var array<string, string> code => img HTML */
    public array $smileys;

    /** Pattern matching any code as a separate word, longest code first; null when there are none. */
    public ?string $smileyPattern;

    /**
     * @param list<array{codes: list<string>, url: string, title: string, width: int, height: int}> $smileys
     * @param array<string, CustomTag> $tags tag => compiled definition
     */
    public function __construct(
        public int $version,
        array $smileys = [],
        public array $tags = [],
    ) {
        $map = [];

        foreach ($smileys as $smiley) {
            foreach ($smiley['codes'] as $code) {
                if ($code === '' || isset($map[$code])) {
                    continue;
                }

                $map[$code] = '<img src="' . self::escape($smiley['url']) . '" alt="' . self::escape($code)
                    . '" title="' . self::escape($smiley['title']) . '" width="' . max(1, $smiley['width'])
                    . '" height="' . max(1, $smiley['height']) . '" class="bb-smiley" loading="lazy">';
            }
        }

        $codes = array_map('strval', array_keys($map));
        usort($codes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->smileys = $map;
        $this->smileyPattern = $codes === [] ? null : '~(?<!\S)('
            . implode('|', array_map(static fn(string $code): string => preg_quote($code, '~'), $codes))
            . ')(?![^\s.,!?;:)])~u';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
