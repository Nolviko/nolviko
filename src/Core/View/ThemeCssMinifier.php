<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

/** Conservative CSS whitespace compaction; quoted content/comments are retained, delimiters must balance. */
final class ThemeCssMinifier
{
    public static function compact(string $source): string
    {
        $output = '';
        $space = false;
        $stack = [];
        $length = strlen($source);
        for ($index = 0; $index < $length; ++$index) {
            $char = $source[$index];
            if (str_contains(" \t\r\n", $char)) {
                $space = true;
                continue;
            }
            if (
                $space && $output !== '' && !str_contains('{;}', substr($output, -1))
                && !str_contains('{};', $char)
            ) {
                $output .= ' ';
            }
            $space = false;
            if ($char === '/' && ($source[$index + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $index + 2);
                if ($end === false) {
                    throw new TemplateException('theme_css_invalid');
                }
                $output .= substr($source, $index, $end + 2 - $index);
                $index = $end + 1;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $output .= $char;
                $closed = false;
                while (++$index < $length) {
                    $output .= $source[$index];
                    if ($source[$index] === '\\' && $index + 1 < $length) {
                        $output .= $source[++$index];
                    } elseif ($source[$index] === $quote) {
                        $closed = true;
                        break;
                    }
                }
                if (!$closed) {
                    throw new TemplateException('theme_css_invalid');
                }
                continue;
            }
            if (str_contains('{([', $char)) {
                $stack[] = $char;
            } elseif (str_contains('})]', $char)) {
                $expected = ['}' => '{', ')' => '(', ']' => '['][$char];
                if (array_pop($stack) !== $expected) {
                    throw new TemplateException('theme_css_invalid');
                }
            }
            $output .= $char;
        }
        if ($stack !== []) {
            throw new TemplateException('theme_css_invalid');
        }
        return $output;
    }
}
