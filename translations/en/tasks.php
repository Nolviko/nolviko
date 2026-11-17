<?php

declare(strict_types=1);

return [
    'tasks.title' => 'Background tasks',
    'tasks.cache' => 'Optional cache',
    'tasks.policy' => 'The forum works without cron or a worker. After pages are sent to visitors, the forum '
        . 'runs a short batch of tasks (at most every 15 seconds); this button runs up to 10 tasks now. On a quiet '
        . 'site, tasks wait for the next visit.',
    'tasks.automatic_last' => 'Last automatic run: {minutes, plural, =0 {less than a minute ago} one {# minute ago} '
        . 'other {# minutes ago}}.',
    'tasks.automatic_never' => 'Visits have not run any tasks yet.',
    'tasks.job_sitemap' => 'Sitemap',
    'tasks.cache_policy' => 'Redis is optional and off by default. Test the IP connection before '
        . 'enabling selected cache features. Non-local servers require verified TLS. '
        . 'Passwords are never shown.',
    'tasks.cache_state' => '{enabled, select, 1 {Redis is enabled.} other {File cache is active.}}',
    'tasks.cli_state' => '{enabled, select, 1 {Command-line worker is enabled.} other {Command-line '
        . 'worker is disabled.}}',
    'tasks.fallback' => 'Redis was unavailable; the forum safely returned to a fresh file cache. '
        . 'Test and enable again to reconnect.',
    'tasks.host' => 'Server IP address',
    'tasks.port' => 'Port',
    'tasks.username' => 'Redis username (optional)',
    'tasks.password' => 'Redis password',
    'tasks.database' => 'Redis database number',
    'tasks.tls' => 'Use verified TLS',
    'tasks.peer' => 'Certificate server name (optional)',
    'tasks.features' => 'Enable individually',
    'tasks.theme_cache' => 'Theme rendering cache',
    'tasks.presence_cache' => 'Approximate online activity cache',
    'tasks.preflight' => 'Test connection',
    'tasks.enable' => 'Test and enable',
    'tasks.disable' => 'Return to file cache',
    'tasks.run' => 'Run a small batch now',
    'tasks.retry' => 'Retry failed task',
    'tasks.cli_enable' => 'Enable command-line worker',
    'tasks.cli_disable' => 'Disable command-line worker',
    'tasks.status' => 'Status',
    'tasks.all' => 'All',
    'tasks.filter' => 'Filter',
    'tasks.id' => 'Number',
    'tasks.type' => 'Task',
    'tasks.attempts' => 'Attempts',
    'tasks.action' => 'Action',
    'tasks.empty' => 'No tasks match this view.',
    'tasks.older' => 'Older tasks',
    'tasks.updated' => 'The requested change was applied.',
    'tasks.invalid' => 'Check the values and try again.',
    'tasks.denied' => 'Sign in as an administrator and confirm your password before changing '
        . 'tasks or cache settings.',
    'tasks.unavailable' => 'The task or cache service is unavailable. Your previous cache '
        . 'configuration is retained if testing failed.',
    'tasks.warning' => 'Waiting too long: {overdue, number, integer}. Expired leases: {expired, '
        . 'number, integer}. Failed tasks: {failed, number, integer}.',
    'tasks.run_result' => 'Completed: {completed, number, integer}. In progress: {progress, number, '
        . 'integer}. Failed: {failed, number, integer}.',
    'tasks.status_pending' => 'Waiting',
    'tasks.status_running' => 'Running',
    'tasks.status_failed' => 'Failed',
    'tasks.status_completed' => 'Completed',
    'tasks.unknown' => 'Extension task',
    'tasks.job_post' => 'Discussion notifications',
    'tasks.job_export' => 'Personal archive',
    'tasks.job_delete' => 'Account removal',
    'tasks.job_mail' => 'Notification email',
    'tasks.details' => 'Technical details',
    'tasks.count_capped' => 'Summary counts stop at 1000 per category.',
];
