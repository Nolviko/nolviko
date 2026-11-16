<?php

declare(strict_types=1);

namespace Nolviko\Core\Logging;

use Nolviko\Core\Config\Secret;
use Throwable;

/**
 * Removes secrets from log context and text before anything is written.
 *
 * Layers, from exact to heuristic: known secret values (config), Secret objects, sensitive key names at any
 * depth, then patterns in free text (URL/DSN passwords, `password=…`, Authorization and Cookie headers).
 * Objects are never dumped: only exceptions are expanded, everything else becomes its class name.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    public const MAX_DEPTH = 8;

    private const TRUNCATED = '[depth]';

    /** Secret values shorter than this are not scrubbed from free text (a one-letter value would mangle it). */
    private const MIN_KNOWN_SECRET_LENGTH = 6;

    private const MAX_TRACE_FRAMES = 20;

    private const MAX_PREVIOUS = 3;

    /** Matched against normalized key names (lowercase, without `-` and `_`). */
    private const SENSITIVE_KEY_PATTERN =
        '/pass(word|wd|phrase)?$|pwd|secret|token|authorization|cookie|session|apikey|privatekey|credential|signature/';

    /** @var list<array{0: string, 1: string}> Pattern => replacement, applied in order. */
    private const TEXT_PATTERNS = [
        // scheme://user:password@host (DSN or URL credentials).
        ['~(\b[a-z][a-z0-9+.-]*://[^\s:/@]*:)[^\s@/]+@~i', '$1' . self::REDACTED . '@'],
        // Authorization / Cookie header values up to the end of the line.
        ['~\b((?:proxy-)?authorization|set-cookie|cookie)(\s*[:=]\s*)[^\r\n]+~i', '$1$2' . self::REDACTED],
        // key=value or key: value for sensitive names (PDO DSN `password=…;`, query strings, messages).
        [
            '~\b([a-z0-9_-]*(?:pass(?:word|wd|phrase)?|pwd|secret|token|api[_-]?key|private[_-]?key)[a-z0-9_-]*)'
                . '(\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s;&,]+)~i',
            '$1$2' . self::REDACTED,
        ],
    ];

    /** @var list<string> */
    private readonly array $knownSecrets;

    /**
     * @param list<Secret|string> $knownSecrets Exact values to scrub anywhere, e.g. the configured passwords.
     */
    public function __construct(array $knownSecrets = [])
    {
        $values = [];

        foreach ($knownSecrets as $secret) {
            $value = $secret instanceof Secret ? $secret->reveal() : $secret;

            if (strlen($value) >= self::MIN_KNOWN_SECRET_LENGTH) {
                $values[] = $value;
            }
        }

        // Longest first, so a secret that contains another is replaced whole.
        usort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->knownSecrets = $values;
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    public function context(array $context): array
    {
        return $this->redactArray($context, 0);
    }

    public function text(string $text): string
    {
        if ($this->knownSecrets !== []) {
            $text = str_replace($this->knownSecrets, self::REDACTED, $text);
        }

        foreach (self::TEXT_PATTERNS as [$pattern, $replacement]) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Class, redacted message, location and an argument-free trace; previous exceptions likewise.
     *
     * @return array<string, mixed>
     */
    public function exception(Throwable $error, int $depth = 0): array
    {
        $data = [
            'class' => $error::class,
            'message' => $this->text($error->getMessage()),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => self::trace($error),
        ];

        if ($error->getPrevious() !== null && $depth < self::MAX_PREVIOUS) {
            $data['previous'] = $this->exception($error->getPrevious(), $depth + 1);
        }

        return $data;
    }

    public static function isSensitiveKey(string|int $key): bool
    {
        return is_string($key)
            && preg_match(self::SENSITIVE_KEY_PATTERN, str_replace(['-', '_'], '', strtolower($key))) === 1;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [self::TRUNCATED];
        }

        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = self::isSensitiveKey($key) ? self::REDACTED : $this->value($value, $depth + 1);
        }

        return $redacted;
    }

    private function value(mixed $value, int $depth): mixed
    {
        return match (true) {
            is_string($value) => $this->text($value),
            is_array($value) => $this->redactArray($value, $depth),
            $value instanceof Secret => self::REDACTED,
            $value instanceof Throwable => $this->exception($value),
            // Stringable and JsonSerializable objects too: their output is not known to be safe.
            is_object($value) => $value::class,
            is_resource($value) => 'resource',
            default => $value,
        };
    }

    /**
     * Frames as `file:line class::function`, never arguments (they may hold credentials).
     *
     * @return list<string>
     */
    private static function trace(Throwable $error): array
    {
        $frames = [];

        foreach (array_slice($error->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $frames[] = ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? 0) . ' '
                . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
        }

        return $frames;
    }
}
