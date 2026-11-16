<?php

declare(strict_types=1);

namespace Nolviko\Tools\I18n;

use Nolviko\Core\Locale\MessageTranslator;

/**
 * Finds message IDs used in PHP (`->translate('id'`) and Twig (`t('id'`) and reports those missing
 * from a translator, normally the English catalog. Only literal IDs can be checked; keep IDs literal.
 */
final class TranslationKeyScanner
{
    private const USAGE_PATTERN =
        '/(?:->translate|(?<![\w$>])t)\(\s*([\'"])([a-z][a-z0-9_]*\.[A-Za-z0-9_.]+)\1/';

    /**
     * @param list<string> $directories
     *
     * @return list<Finding> One finding per usage, in file order.
     */
    public function usages(array $directories): array
    {
        $usages = [];

        foreach (SourceFiles::find($directories) as $path) {
            $content = file_get_contents($path);
            preg_match_all(self::USAGE_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[2] as [$id, $offset]) {
                $usages[] = new Finding($path, SourceFiles::lineOf($content, $offset), $id);
            }
        }

        return $usages;
    }

    /**
     * @param list<string> $directories
     *
     * @return list<Finding> Usages whose ID the translator does not know.
     */
    public function missing(array $directories, MessageTranslator $translator): array
    {
        return array_values(
            array_filter(
                $this->usages($directories),
                static fn(Finding $usage): bool => !$translator->has($usage->text),
            ),
        );
    }
}
