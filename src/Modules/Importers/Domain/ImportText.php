<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use XMLReader;

/** Explicit charset decoding and plain BBCode normalization; invalid bytes/oversize rows stop the job. */
final class ImportText
{
    public static function decode(string $text, string $charset): string
    {
        if (
            !in_array($charset, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) || strlen($text) > 131072
            || !mb_check_encoding($text, $charset) || str_contains($text, "\0")
        ) {
            throw new InvalidArgumentException('import_text_invalid');
        }
        return $charset === 'UTF-8' ? $text : mb_convert_encoding($text, 'UTF-8', $charset);
    }

    public static function body(string $text, string $engine, string $charset): string
    {
        $text = self::decode($text, $charset);
        if ($engine === 'phpbb') {
            // Stored UID suffixes and s9e transport wrappers are not trusted rendered HTML.
            $text = preg_replace('/\[([^\]\r\n]*?):[a-z0-9]{5,16}\]/i', '[$1]', $text);
            if (str_starts_with($text, '<r>') || str_starts_with($text, '<t>')) {
                if (preg_match('/<!DOCTYPE|<!ENTITY|<\?/i', $text) === 1) {
                    throw new InvalidArgumentException('import_text_invalid');
                }
                $text = self::xml($text);
            }
        } else {
            $text = html_entity_decode(
                str_replace(['<br>', '<br />', '<br/>'], "\n", $text),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }
        if (mb_strlen($text, 'UTF-8') > 50000) {
            throw new InvalidArgumentException('import_body_oversize');
        }
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private static function xml(string $text): string
    {
        if (!class_exists(XMLReader::class)) {
            throw new InvalidArgumentException('import_xml_reader_required');
        }
        $previous = libxml_use_internal_errors(true);
        $reader = new XMLReader();
        try {
            if (!$reader->XML($text, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new InvalidArgumentException('import_text_invalid');
            }
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $result = '';
            $root = false;
            while ($reader->read()) {
                if (
                    $reader->depth > 64 || in_array(
                        $reader->nodeType,
                        [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF, XMLReader::PI],
                        true
                    )
                ) {
                    throw new InvalidArgumentException('import_text_invalid');
                }
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 0) {
                    if ($root || !in_array($reader->name, ['r', 't'], true)) {
                        throw new InvalidArgumentException('import_text_invalid');
                    }
                    $root = true;
                }
                if (
                    in_array(
                        $reader->nodeType,
                        [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE],
                        true
                    )
                ) {
                    $result .= $reader->value;
                }
            }
            if (!$root || libxml_get_errors() !== []) {
                throw new InvalidArgumentException('import_text_invalid');
            }
            return $result;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function timestamp(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[0-9]{1,10}$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('import_timestamp_invalid');
        }
        return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
