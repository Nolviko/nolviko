<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Installer;

use Nolviko\Core\Installer\SetupCode;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * M01.14 takeover protection: only someone who can read the server's storage can continue the installer.
 */
final class SetupCodeTest extends TestCase
{
    private TemporaryDirectory $storage;

    private ArraySession $session;

    private SetupCode $code;

    protected function setUp(): void
    {
        $this->storage = new TemporaryDirectory();
        $this->session = new ArraySession();
        $this->code = new SetupCode($this->storage->path, $this->session);
    }

    protected function tearDown(): void
    {
        $this->storage->remove();
    }

    public function testCodeFileIsCreatedOncePrivatelyAndNotOverwritten(): void
    {
        self::assertTrue($this->code->ensure());
        $first = (string) file_get_contents($this->code->path());

        self::assertMatchesRegularExpression('/^([A-HJKMNP-Z2-9]{5}-){3}[A-HJKMNP-Z2-9]{5}\n$/D', $first);
        self::assertTrue($this->code->ensure());
        self::assertSame($first, file_get_contents($this->code->path()));

        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->code->path()) & 0777);
            self::assertSame(0700, fileperms(dirname($this->code->path())) & 0777);
        }
    }

    public function testWrongCodeProvesNothing(): void
    {
        $this->code->ensure();

        foreach (['', 'AAAAA-BBBBB-CCCCC-DDDDD', 'x'] as $wrong) {
            self::assertFalse($this->code->prove($wrong));
        }

        self::assertFalse($this->code->isProven());
        self::assertSame(0, $this->session->regenerations);
    }

    public function testRightCodeIsAcceptedLooselyAndRegeneratesTheSession(): void
    {
        $this->code->ensure();
        $entered = strtolower(str_replace('-', ' ', trim((string) file_get_contents($this->code->path()))));

        self::assertTrue($this->code->prove('  ' . $entered . "\n"));
        self::assertTrue($this->code->isProven());
        self::assertSame(1, $this->session->regenerations);
    }

    public function testNewCodeFileInvalidatesEarlierProof(): void
    {
        $this->code->ensure();
        $this->code->prove((string) file_get_contents($this->code->path()));
        $this->code->remove();
        $this->code->ensure();

        self::assertFalse($this->code->isProven());
    }

    public function testWithoutStorageThereIsNoCodeAndNoProof(): void
    {
        $code = new SetupCode($this->storage->path . '/missing/deeper', $this->session);
        file_put_contents($this->storage->path . '/missing', 'a file, not a directory');

        self::assertFalse($code->ensure());
        self::assertFalse($code->prove(''));
    }
}
