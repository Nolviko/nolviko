<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Closure;
use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Http\ErrorReporter;
use Nolviko\Core\Http\FrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The real application served with a temporary config directory, so tests choose the installation state.
 */
final class SiteFixture
{
    /** Test-only values; redaction tests assert they never appear in output. */
    public const DB_PASSWORD = 'db-Pa55-never-shown';

    public const APP_SECRET = '5ecret0f4pp5ecret0f4pp5ecret0f4pp5ecret0f4pp5ecret0f4pp5ecret00';

    public readonly string $configDirectory;

    private readonly TemporaryDirectory $directory;

    private function __construct()
    {
        $this->directory = new TemporaryDirectory();
        $this->configDirectory = $this->directory->path . '/config';
        mkdir($this->configDirectory);
    }

    public static function notConfigured(): self
    {
        return new self();
    }

    public static function configured(): self
    {
        $site = new self();
        $site->writeConfig(self::validConfig());

        return $site;
    }

    public static function installed(): self
    {
        $site = self::configured();
        $site->lock();

        return $site;
    }

    /**
     * @return array<string, array<string, string|int>>
     */
    public static function validConfig(): array
    {
        return [
            'database' => [
                'driver' => 'mysql',
                'host' => '192.0.2.1',
                'port' => 3306,
                'name' => 'forum',
                'user' => 'forum',
                'password' => self::DB_PASSWORD,
            ],
            'app' => ['secret' => self::APP_SECRET],
        ];
    }

    /**
     * @param array<mixed>|string $config An array to export, or raw PHP source.
     */
    public function writeConfig(array|string $config): string
    {
        $source = is_string($config) ? $config : '<?php return ' . var_export($config, true) . ';';
        $file = $this->configDirectory . '/' . InstallationState::CONFIG_FILE;
        file_put_contents($file, $source);

        return $file;
    }

    public function lock(): void
    {
        touch($this->configDirectory . '/' . InstallationState::LOCK_FILE);
    }

    public function storagePath(): string
    {
        return $this->directory->path . '/storage';
    }

    /**
     * @param Request|Closure(): Request $request
     * @param array<string, string> $environment Merged over a storage-less default environment.
     */
    public function respond(
        Request|Closure $request,
        ?ErrorReporter $reporter = new RecordingReporter(),
        array $environment = [],
        ?string $projectDirectory = null,
    ): Response {
        return FrontController::respond(
            $projectDirectory ?? self::root(),
            $request instanceof Closure ? $request : static fn(): Request => $request,
            $reporter,
            [FrontController::STORAGE_ENVIRONMENT => $this->directory->path . '/no-storage', ...$environment],
            $this->configDirectory,
        );
    }

    public function remove(): void
    {
        $this->directory->remove();
    }

    public static function root(): string
    {
        return (string) realpath(dirname(__DIR__, 2));
    }
}
