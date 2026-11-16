<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use DateTimeImmutable;
use ReflectionClass;

/**
 * Checks what a template may receive: scalars, null, arrays of them, SafeHtml, DateTimeImmutable (for the
 * date filters) and readonly ViewModel objects (their public properties checked the same way). Anything else,
 * such as a closure, a connection, the session or a mutable object (including DateTime), is refused before
 * rendering with `view_context_invalid:<path>`.
 */
final class ViewContext
{
    private const MAX_DEPTH = 16;

    /** @var array<class-string, bool> */
    private static array $readonly = [];

    /**
     * @param array<string, mixed> $context
     *
     * @throws TemplateException
     */
    public static function assert(array $context): void
    {
        foreach ($context as $key => $value) {
            self::assertValue($value, (string) $key, 0);
        }
    }

    private static function assertValue(mixed $value, string $path, int $depth): void
    {
        if (
            $value === null
            || is_scalar($value)
            || $value instanceof SafeHtml
            || $value instanceof DateTimeImmutable
        ) {
            return;
        }

        if ($depth < self::MAX_DEPTH && is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertValue($item, $path . '.' . $key, $depth + 1);
            }

            return;
        }

        if ($depth < self::MAX_DEPTH && $value instanceof ViewModel && self::isReadonly($value)) {
            foreach (get_object_vars($value) as $name => $property) {
                self::assertValue($property, $path . '.' . $name, $depth + 1);
            }

            return;
        }

        throw new TemplateException('view_context_invalid:' . $path);
    }

    private static function isReadonly(ViewModel $value): bool
    {
        return self::$readonly[$value::class] ??= (new ReflectionClass($value))->isReadOnly();
    }
}
