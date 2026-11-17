<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/** Trusted admin preparation only; creates no public PHP entry and performs no live activation. */
final class RecoveryPreparation
{
    public function __construct(private readonly string $applicationRoot, private readonly string $storageRoot)
    {
    }

    /** @return array<string, mixed> */
    public function prepare(
        string $job,
        int $actor,
        string $origin,
        array $proxies,
        string $shell,
    ): array {
        $storage = realpath($this->storageRoot);
        $application = realpath($this->applicationRoot);
        $public = realpath($this->applicationRoot . '/public');

        // Owner decision 2026-11-17 (as UpdatePreflight): storage/ inside the application folder is accepted while
        // its deny-all .htaccess stands; the file switch replaces files one by one and never touches storage/.
        $denyFile = $this->storageRoot . '/.htaccess';
        $denied = $storage !== false && $application !== false
            && strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $storage))
                === rtrim(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $application)), '/') . '/storage'
            && is_file($denyFile) && !is_link($denyFile)
            && preg_match('~Require\s+all\s+denied~i', (string) file_get_contents($denyFile)) === 1;

        if (
            $storage === false || $public === false || $application === false || is_link($this->storageRoot)
            || (!$denied && str_starts_with(
                strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $storage)) . '/',
                rtrim(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $application)), '/') . '/',
            ))
            || str_starts_with(
                strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $storage)) . '/',
                rtrim(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $public)), '/') . '/',
            )
        ) {
            throw new RuntimeException('recovery_storage_not_private');
        }

        $directory = $storage . '/recovery';

        if (
            is_link($directory) || (file_exists($directory) && !is_dir($directory))
            || (!is_dir($directory) && !mkdir($directory, 0700))
        ) {
            throw new RuntimeException('recovery_storage_failed');
        }

        $progress = (new FrozenRecoveryRuntime($this->applicationRoot, $directory))->prepare($job);

        if (!$progress['complete']) {
            return ['progress' => $progress, 'grant' => null];
        }

        $grants = $directory . '/grants';

        if (
            is_link($grants) || (file_exists($grants) && !is_dir($grants))
            || (!is_dir($grants) && !mkdir($grants, 0700))
        ) {
            throw new RuntimeException('recovery_storage_failed');
        }

        RecoveryBridgePublisher::publish(
            (string) realpath($this->applicationRoot),
            $storage,
            $directory,
            $job,
            $origin,
            $proxies,
            $shell,
        );

        return [
            'progress' => $progress,
            // 15 minutes, renewed by every step (RecoveryGrantStore): the one-click driver runs backup, file switch
            // and migrations with it in one go.
            'grant' => (new RecoveryGrantStore($grants, $public))->issue($job, $actor, 900),
        ];
    }
}
