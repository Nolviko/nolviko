<?php

declare(strict_types=1);

namespace Nolviko\Tools;

/**
 * Run a command without a shell; stdout goes to $logFile and stderr to "$logFile.diagnostic.log".
 */
final class ProcessRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment Null inherits the current environment.
     *
     * @return int The process exit code.
     */
    public static function run(
        array $command,
        string $logFile,
        ?string $workingDirectory = null,
        ?array $environment = null,
    ): int {
        $descriptors = [
            1 => ['file', $logFile, 'w'],
            2 => ['file', self::diagnosticLog($logFile), 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory, $environment);

        return proc_close($process);
    }

    public static function diagnosticLog(string $logFile): string
    {
        return $logFile . '.diagnostic.log';
    }
}
