<?php

declare(strict_types=1);

namespace Nolviko\Core\Logging;

use Closure;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * PSR-3 logger writing one redacted JSON object per line to `<directory>/nolviko-YYYY-MM-DD.log` (UTC).
 *
 * Bounded for shared hosting: each daily file stops at a size cap (one marker line, then later records of
 * that day are dropped) and files older than the retention are deleted when a new day starts. Logging
 * never fails a request: any write problem goes to the fallback (PHP's error_log by default) or nowhere.
 */
final class JsonFileLogger extends AbstractLogger
{
    public const FILE_PREFIX = 'nolviko-';

    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    public const DEFAULT_RETENTION_DAYS = 14;

    public const CAP_MESSAGE = 'log_size_cap_reached';

    /** Context key holding the client address; written according to the IpPolicy. */
    public const CLIENT_IP = 'client_ip';

    private const LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private const DIRECTORY_MODE = 0770;

    private const SECONDS_PER_DAY = 86400;

    /** Longer than a marker line, shorter than any useful record. */
    private const TAIL_BYTES = 160;

    private readonly Closure $clock;

    private readonly Closure $fallback;

    /**
     * @param Closure(): float|null $clock Unix time with fractions; null means microtime(true).
     * @param Closure(string): void|null $fallback Receives the JSON line when the file write fails.
     */
    public function __construct(
        private readonly string $directory,
        private readonly Redactor $redactor = new Redactor(),
        private readonly IpPolicy $ipPolicy = IpPolicy::Truncated,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        ?Closure $clock = null,
        ?Closure $fallback = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->fallback = $fallback ?? static function (string $line): void {
            error_log(rtrim($line));
        };
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     *
     * @throws InvalidArgumentException For a level outside PSR-3 (the only exception this logger throws).
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('log_level_invalid');
        }

        try {
            $now = ($this->clock)();
            $this->write($now, $this->line($now, $level, (string) $message, $context));
        } catch (Throwable) {
            // Logging must never break the request.
        }
    }

    public function fileFor(float $time): string
    {
        return $this->directory . '/' . self::FILE_PREFIX . gmdate('Y-m-d', (int) $time) . '.log';
    }

    /**
     * @param array<mixed> $context
     */
    private function line(float $time, string $level, string $message, array $context): string
    {
        $address = $context[self::CLIENT_IP] ?? null;
        unset($context[self::CLIENT_IP]);
        $record = [
            'time' => gmdate('Y-m-d\TH:i:s', (int) $time) . sprintf('.%03dZ', (int) (fmod($time, 1) * 1000)),
            'level' => $level,
            'message' => $this->redactor->text($message),
        ];
        $clientIp = $this->ipPolicy->apply(is_string($address) ? $address : null);

        if ($clientIp !== null) {
            $record['client_ip'] = $clientIp;
        }

        if ($context !== []) {
            $record['context'] = $this->redactor->context($context);
        }

        return json_encode($record, self::JSON_FLAGS) . "\n";
    }

    private function write(float $time, string $line): void
    {
        $file = $this->fileFor($time);

        // Not recursive: storage/ must exist already; a missing storage never gets created by logging.
        if (!is_dir($this->directory) && !@mkdir($this->directory, self::DIRECTORY_MODE)) {
            ($this->fallback)($line);

            return;
        }

        clearstatcache(true, $file);
        $size = is_file($file) ? (int) filesize($file) : 0;

        if ($size === 0) {
            $this->deleteExpired($time);
        }

        if ($size >= $this->maxBytes) {
            return;
        }

        if ($size + strlen($line) > $this->maxBytes) {
            if (self::endsWithCapMarker($file)) {
                return;
            }

            // The marker is the last line of the day; it may exceed the cap by its own few bytes.
            $line = $this->line($time, LogLevel::WARNING, self::CAP_MESSAGE, []);
        }

        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            ($this->fallback)($line);
        }
    }

    /**
     * Only read on the rare overflow path: the tail of a full file holds the marker once it was written.
     */
    private static function endsWithCapMarker(string $file): bool
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        fseek($handle, -self::TAIL_BYTES, SEEK_END);
        $tail = (string) fread($handle, self::TAIL_BYTES);
        fclose($handle);

        return str_contains($tail, '"' . self::CAP_MESSAGE . '"');
    }

    private function deleteExpired(float $time): void
    {
        $oldest = $this->fileFor($time - $this->retentionDays * self::SECONDS_PER_DAY);

        foreach (glob($this->directory . '/' . self::FILE_PREFIX . '*.log') ?: [] as $file) {
            // Same-length names compare chronologically.
            if (strlen($file) === strlen($oldest) && $file < $oldest) {
                @unlink($file);
            }
        }
    }
}
