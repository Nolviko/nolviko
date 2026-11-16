<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * One permission: a namespaced key (`forum.thread.create`), its value type and the values it accepts.
 *
 * Values are stored as text: bool as `0`/`1`, int as a decimal within [min, max], enum as one of the
 * options. `encode()` is the only way a value reaches storage, so an invalid value can never be saved.
 *
 * ADR-011: when several groups give different typed values, the most generous wins. Ints declare which
 * direction is generous (`higher` or `lower`); enum options are declared from most restrictive to most
 * generous. Without any rule the default applies: the most restrictive value unless one is given.
 */
final class PermissionDefinition
{
    /** Two to five lowercase dot-separated segments; the first is the owning module or extension. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,4}$/D';

    public const MAX_KEY_LENGTH = 100;

    public const HIGHER = 'higher';

    public const LOWER = 'lower';

    private const OPTION_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /**
     * @param list<string> $options Enum values, most restrictive first (required for Enum, empty otherwise).
     * @param string $generous Int only: HIGHER or LOWER.
     * @param bool|int|string|null $default Null means the most restrictive value.
     *
     * @throws PermissionError `permission_key_invalid` or `permission_definition_invalid`.
     */
    public function __construct(
        public readonly string $key,
        public readonly PermissionType $type,
        public readonly array $options = [],
        public readonly int $min = 0,
        public readonly int $max = PHP_INT_MAX,
        public readonly string $generous = self::HIGHER,
        private readonly bool|int|string|null $default = null,
    ) {
        if (strlen($key) > self::MAX_KEY_LENGTH || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new PermissionError('permission_key_invalid:' . $key);
        }

        if (!$this->hasValidShape() || !in_array($generous, [self::HIGHER, self::LOWER], true)) {
            throw new PermissionError('permission_definition_invalid:' . $key);
        }

        if ($default !== null) {
            try {
                $this->encode($default);
            } catch (PermissionError) {
                throw new PermissionError('permission_definition_invalid:' . $key);
            }
        }
    }

    public static function bool(string $key): self
    {
        return new self($key, PermissionType::Bool);
    }

    public static function int(string $key, int $min, int $max, string $generous = self::HIGHER): self
    {
        return new self($key, PermissionType::Int, min: $min, max: $max, generous: $generous);
    }

    /**
     * @param list<string> $options
     */
    public static function enum(string $key, array $options): self
    {
        return new self($key, PermissionType::Enum, $options);
    }

    /**
     * Validate a value from a form, import or API and return its storage text.
     *
     * Accepted input: bool `true`/`false`, `1`/`0` (int or string); int an integer or a decimal string in
     * range; enum one of the options exactly. Anything else is rejected, never coerced.
     *
     * @throws PermissionError `permission_value_invalid:<key>`.
     */
    public function encode(mixed $value): string
    {
        $encoded = match ($this->type) {
            PermissionType::Bool => match ($value) {
                true, 1, '1' => '1',
                false, 0, '0' => '0',
                default => null,
            },
            PermissionType::Int => $this->encodeInt($value),
            PermissionType::Enum => is_string($value) && in_array($value, $this->options, true) ? $value : null,
        };

        return $encoded ?? throw new PermissionError('permission_value_invalid:' . $this->key);
    }

    /**
     * Validate a rule value (ADR-011) and return its storage text: like encode(), and for bool keys also
     * `never` (absolute deny). Inherit is not a value: it is the absence of a rule.
     *
     * @throws PermissionError `permission_value_invalid:<key>`.
     */
    public function encodeRule(mixed $value): string
    {
        if ($this->type === PermissionType::Bool && $value === Precedence::NEVER) {
            return Precedence::NEVER;
        }

        return $this->encode($value);
    }

    /**
     * Read a stored value back; text that no longer fits the definition (an option was removed) is rejected.
     *
     * @throws PermissionError `permission_value_invalid:<key>`.
     */
    public function decode(string $stored): bool|int|string
    {
        $canonical = $this->encode($stored);

        return match ($this->type) {
            PermissionType::Bool => $canonical === '1',
            PermissionType::Int => (int) $canonical,
            PermissionType::Enum => $canonical,
        };
    }

    /**
     * The value that applies when no rule exists (ADR-011 L5).
     */
    public function defaultValue(): bool|int|string
    {
        if ($this->default !== null) {
            return $this->decode($this->encode($this->default));
        }

        return match ($this->type) {
            PermissionType::Bool => false,
            PermissionType::Int => $this->generous === self::HIGHER ? $this->min : $this->max,
            PermissionType::Enum => $this->options[0],
        };
    }

    /**
     * The most generous of several decoded typed values (ADR-011: groups combine generously).
     *
     * @param non-empty-list<int|string> $values
     */
    public function mostGenerous(array $values): int|string
    {
        if ($this->type === PermissionType::Enum) {
            $ranks = array_map(fn(int|string $value): int => (int) array_search($value, $this->options, true), $values);

            return $this->options[max($ranks)];
        }

        return $this->generous === self::HIGHER ? max($values) : min($values);
    }

    private function encodeInt(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }

        return is_int($value) && $value >= $this->min && $value <= $this->max ? (string) $value : null;
    }

    private function hasValidShape(): bool
    {
        if ($this->type !== PermissionType::Enum) {
            return $this->options === [] && ($this->type !== PermissionType::Int || $this->min <= $this->max);
        }

        $valid = array_filter(
            $this->options,
            static fn(mixed $option): bool => is_string($option) && preg_match(self::OPTION_PATTERN, $option) === 1,
        );

        return count($this->options) >= 2
            && count($valid) === count($this->options)
            && count(array_unique($this->options)) === count($this->options)
            && array_is_list($this->options);
    }
}
