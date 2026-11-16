<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Nolviko\Core\Session\Session;

/**
 * Installer takeover protection: a one-time code the site owner reads from `storage/install/setup-code.txt`.
 *
 * The file is created on the first visit of the installer (0600 in a 0700 directory, never web-readable).
 * Entering the code once proves file access; the session then remembers a hash of that code, so creating a
 * new code file invalidates every earlier proof. 20 base-32 characters carry 100 random bits, which makes
 * guessing pointless without rate limiting. The installer removes the file when installation completes.
 */
final class SetupCode
{
    public const FILE = 'install/setup-code.txt';

    private const SESSION_KEY = '_installer_owner';

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 20;

    private const GROUP = 5;

    public function __construct(
        private readonly string $storage,
        private readonly Session $session,
    ) {
    }

    /**
     * Creates the code file if it does not exist yet; false when storage is not writable.
     */
    public function ensure(): bool
    {
        if ($this->current() !== null) {
            return true;
        }

        $directory = dirname($this->path());

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }

        $code = '';

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $text = implode('-', str_split($code, self::GROUP)) . "\n";

        // 'x' never overwrites a code another request created at the same moment.
        $handle = @fopen($this->path(), 'x');

        if ($handle !== false) {
            fwrite($handle, $text);
            fclose($handle);
            @chmod($this->path(), 0600);
        }

        return $this->current() !== null;
    }

    /**
     * Checks an entered code (case, spaces and dashes ignored) and, if it matches, records the proof in the
     * session under a new session ID.
     */
    public function prove(string $entered): bool
    {
        $current = $this->current();

        if ($current === null || !hash_equals($current, self::normalize($entered))) {
            return false;
        }

        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, hash('sha256', $current));

        return true;
    }

    public function isProven(): bool
    {
        $current = $this->current();
        $proof = $this->session->get(self::SESSION_KEY);

        return $current !== null && is_string($proof) && hash_equals(hash('sha256', $current), $proof);
    }

    public function remove(): void
    {
        @unlink($this->path());
    }

    public function path(): string
    {
        return $this->storage . '/' . self::FILE;
    }

    private function current(): ?string
    {
        $code = is_file($this->path()) ? self::normalize((string) @file_get_contents($this->path())) : '';

        return strlen($code) === self::LENGTH ? $code : null;
    }

    private static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $code));
    }
}
