<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

use LogicException;
use WeakMap;

/**
 * A secret value (database password, app secret) that cannot leak through dumps, logs or error pages.
 *
 * The value is not a property of this object but lives in a private static map keyed by it, so var_dump,
 * print_r, var_export, json_encode and exception traces see an empty object. Serialization is refused.
 * Only reveal() returns the value, and only code that hands it to its consumer (e.g. the DBAL driver)
 * should call it.
 */
final class Secret
{
    public const REDACTED = '[redacted]';

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    public function __construct(string $value)
    {
        self::$values ??= new WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? '';
    }

    public function isEmpty(): bool
    {
        return $this->reveal() === '';
    }

    public function length(): int
    {
        return strlen($this->reveal());
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('secret_not_serializable');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('secret_not_serializable');
    }
}
