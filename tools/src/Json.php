<?php

declare(strict_types=1);

namespace Nolviko\Tools;

/**
 * Strict JSON file helpers: malformed input always throws JsonException.
 */
final class Json
{
    /**
     * @return array<mixed>
     */
    public static function readFile(string $path): array
    {
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function writeFile(string $path, mixed $data): void
    {
        file_put_contents($path, self::encode($data, true));
    }

    public static function encode(mixed $data, bool $pretty = false): string
    {
        $flags = JSON_THROW_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }
}
