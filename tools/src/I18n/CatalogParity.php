<?php

declare(strict_types=1);

namespace Nolviko\Tools\I18n;

use MessageFormatter;
use RuntimeException;
use Throwable;

/** Offline catalog gate: exact IDs, valid ICU and matching named argument types, including nested branches. */
final class CatalogParity
{
    /**
     * @param array<string, string> $reference
     * @param array<string, string> $translated
     * @return array<string, string> Message ID => stable finding.
     */
    public function compare(array $reference, array $translated, string $locale): array
    {
        $findings = [];

        foreach ($reference as $id => $message) {
            if (!array_key_exists($id, $translated)) {
                $findings[$id] = 'catalog_key_missing';

                continue;
            }

            try {
                if (
                    !(MessageFormatter::create('en', $message) instanceof MessageFormatter)
                    || !(MessageFormatter::create($locale, $translated[$id]) instanceof MessageFormatter)
                ) {
                    $findings[$id] = 'catalog_icu_invalid';

                    continue;
                }

                if ($this->arguments($message) !== $this->arguments($translated[$id])) {
                    $findings[$id] = 'catalog_arguments_mismatch';
                }
            } catch (Throwable) {
                $findings[$id] = 'catalog_icu_invalid';
            }
        }

        foreach (array_diff_key($translated, $reference) as $id => $message) {
            $findings[$id] = 'catalog_key_unknown';
        }

        ksort($findings, SORT_STRING);

        return $findings;
    }

    /** @return array<string, list<string>> */
    private function arguments(string $message): array
    {
        $offset = 0;
        $arguments = [];
        $this->message($message, $offset, $arguments, false);
        ksort($arguments, SORT_STRING);

        foreach ($arguments as &$types) {
            $types = array_values(array_unique($types));
            sort($types, SORT_STRING);
        }

        return $arguments;
    }

    /** @param array<string, list<string>> $arguments */
    private function message(string $text, int &$offset, array &$arguments, bool $nested): void
    {
        while ($offset < strlen($text)) {
            $character = $text[$offset++];

            if ($character === "'") {
                $this->quote($text, $offset);
            } elseif ($character === '{') {
                $this->argument($text, $offset, $arguments);
            } elseif ($character === '}') {
                if (!$nested) {
                    throw new RuntimeException('catalog_icu_invalid');
                }

                return;
            }
        }

        if ($nested) {
            throw new RuntimeException('catalog_icu_invalid');
        }
    }

    /** @param array<string, list<string>> $arguments */
    private function argument(string $text, int &$offset, array &$arguments): void
    {
        $name = $this->until($text, $offset, ',}');

        if (preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*|[0-9]+)$/D', $name) !== 1) {
            throw new RuntimeException('catalog_argument_invalid');
        }

        if (($text[$offset++] ?? '') === '}') {
            $arguments[$name][] = 'simple';

            return;
        }

        $type = strtolower($this->until($text, $offset, ',}'));
        $arguments[$name][] = $type;

        if (($text[$offset++] ?? '') === '}') {
            return;
        }

        if (!in_array($type, ['plural', 'selectordinal', 'select'], true)) {
            while ($offset < strlen($text)) {
                $character = $text[$offset++];

                if ($character === "'") {
                    $this->quote($text, $offset);
                } elseif ($character === '}') {
                    return;
                }
            }

            throw new RuntimeException('catalog_icu_invalid');
        }

        while ($offset < strlen($text)) {
            // Selector labels are grammar, not argument names. Recurse only inside their message body.
            $this->until($text, $offset, '{}');
            $delimiter = $text[$offset++] ?? '';

            if ($delimiter === '}') {
                return;
            }

            if ($delimiter !== '{') {
                throw new RuntimeException('catalog_icu_invalid');
            }

            $this->message($text, $offset, $arguments, true);
        }

        throw new RuntimeException('catalog_icu_invalid');
    }

    private function until(string $text, int &$offset, string $delimiters): string
    {
        $start = $offset;

        while ($offset < strlen($text) && !str_contains($delimiters, $text[$offset])) {
            ++$offset;
        }

        return trim(substr($text, $start, $offset - $start));
    }

    private function quote(string $text, int &$offset): void
    {
        $next = $text[$offset] ?? '';

        if ($next === "'") {
            ++$offset;

            return;
        }

        // ICU apostrophes quote syntax only before a syntax character; ordinary apostrophes stay literal.
        if ($next === '' || !str_contains('{}#', $next)) {
            return;
        }

        while ($offset < strlen($text)) {
            if ($text[$offset++] === "'") {
                if (($text[$offset] ?? '') !== "'") {
                    return;
                }

                ++$offset;
            }
        }
    }
}
