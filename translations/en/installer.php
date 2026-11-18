<?php

declare(strict_types=1);

/*
 * English texts of the browser installer (M01.13+).
 */

return [
    'installer.prerequisites.intro' => 'This page checks whether this server can run the forum. '
        . 'Fix every failed item, then check again.',
    'installer.prerequisites.runtime' => 'PHP',
    'installer.prerequisites.files' => 'Files and web access',
    'installer.prerequisites.ready' => 'All required checks passed. The database step comes next.',
    'installer.prerequisites.blocked' => 'Some required checks failed. Fix them and check again.',
    'installer.prerequisites.recheck' => 'Check again',
    'installer.prerequisites.continue' => 'Continue to the database settings',
    'installer.form.errors' => 'Please correct the following:',
    'installer.setup_code.label' => 'Setup code',
    'installer.setup_code.help' => 'To prove that you own this site, open the file {file} on your server '
        . '(for example with your hosting file manager or FTP) and enter the code it contains.',
    'installer.database.heading' => 'Database',
    'installer.database.driver' => 'Database type',
    'installer.database.driver_mysql' => 'MySQL 8.4 or MariaDB 10.11+',
    'installer.database.driver_postgresql' => 'PostgreSQL 16+',
    'installer.database.driver_help' => 'MySQL and MariaDB are told apart automatically.',
    'installer.database.host' => 'Database server',
    'installer.database.port' => 'Port',
    'installer.database.port_help' => 'Leave empty for the default port (3306, PostgreSQL 5432).',
    'installer.database.name' => 'Database name',
    'installer.database.user' => 'Database user name',
    'installer.database.password' => 'Database password',
    'installer.database.submit' => 'Test and save',
    'installer.migrate.heading' => 'Database tables',
    'installer.migrate.auto' => 'Continuing automatically…',
    'installer.migrate.pending' => '{count, plural, one {# database update is waiting.} other {# database '
        . 'updates are waiting.}}',
    'installer.migrate.submit' => 'Create the tables',
    'installer.migrate.complete' => 'The database is ready. Next, optionally configure outgoing email.',
    'installer.migrate.continue' => 'Continue to email settings',
    'installer.admin.heading' => 'Administrator account',
    'installer.admin.intro' => 'This account manages the forum. Choose a strong password that you use nowhere '
        . 'else.',
    'installer.admin.username' => 'User name',
    'installer.admin.email' => 'Email address',
    'installer.admin.password' => 'Password',
    'installer.admin.password_confirmation' => 'Repeat the password',
    'installer.admin.submit' => 'Create the account and finish',
    'installer.admin.exists' => 'The administrator account exists already. Finish the installation to open the '
        . 'forum.',
    'installer.admin.finish' => 'Finish the installation',
    'installer.status.passed' => 'Passed',
    'installer.status.warning' => 'Warning',
    'installer.status.failed' => 'Failed',
    'installer.check.php_version' => 'PHP {version} is supported.',
    'installer.check.php_end_of_life' => 'PHP {version} no longer gets security fixes from the PHP project. The '
        . 'forum works, but ask your host for a newer PHP version.',
    'installer.check.extension_missing' => 'The required PHP extension {extension} is not loaded. It is needed for '
        . '{feature, select, database {the database connection} languages {languages, dates and numbers} text '
        . '{handling text} packages {updates and installing extensions and themes} security {passwords, tokens '
        . 'and secure connections} uploads {uploads, avatars and images} sign_in {signing in} other {the '
        . 'forum}}. Ask your host to enable it.',
    'installer.check.extension' => 'The PHP extension {extension} is loaded.',
    'installer.check.memory_limit' => 'The PHP memory limit is {limit}.',
    'installer.check.storage_writable' => 'The storage directory is writable by the web server.',
    'installer.check.web_protected' => 'The {directory} directory cannot be downloaded from the web.',
    'installer.check.web_exposed' => 'Files in the {directory} directory can be downloaded from the web. Point '
        . 'the document root at public/, or make sure the server honours the included .htaccess files '
        . '(nginx needs the sample configuration from the hosting guide).',
    'installer.check.web_unknown' => 'Could not test over HTTP whether the {directory} directory is protected. '
        . 'Open a file in it in your browser after installation; it must not be shown.',
    'installer.mail.heading' => 'Outgoing email (optional)',
    'installer.mail.intro' => 'Choose your hosting mail service or an SMTP server. You can skip '
        . 'SMTP and continue installation.',
    'installer.mail.transport' => 'Delivery method',
    'installer.mail.native' => 'Hosting PHP mail service',
    'installer.mail.smtp_transport' => 'SMTP',
    'installer.mail.smtp' => 'SMTP settings',
    'installer.mail.from_address' => 'Sender email address',
    'installer.mail.from_name' => 'Sender name',
    'installer.mail.host' => 'SMTP server',
    'installer.mail.port' => 'SMTP port (empty: 587 for STARTTLS, 465 for SMTPS)',
    'installer.mail.encryption' => 'Encryption',
    'installer.mail.user' => 'SMTP user name',
    'installer.mail.password' => 'SMTP password',
    'installer.mail.tls' => 'SMTP always requires TLS and a valid server certificate.',
    'installer.mail.recipient' => 'Test recipient email address',
    'installer.mail.test' => 'Send test email without saving',
    'installer.mail.save' => 'Save and continue',
    'installer.mail.skip' => 'Skip SMTP and continue',
    'installer.mail.sent' => 'The server accepted the test email. Check the recipient inbox; '
        . 'settings have not been saved.',
    'installer.mail.password_notice' => 'For your privacy, the password is never filled in again. Re-enter'
        . ' it after testing and before saving.',
    'installer.mail.skip_notice' => 'Skipping selects the hosting PHP mail service. If no sender is '
        . 'set, the first administrator email is used. Check delivery from '
        . 'the admin mail page after installation.',
    'installer.mail.starttls' => 'STARTTLS',
    'installer.mail.smtps' => 'SMTPS',
    'installer.steps.label' => 'Installation steps',
    'installer.steps.prerequisites' => 'Server check',
    'installer.steps.database' => 'Database',
    'installer.steps.migrate' => 'Tables',
    'installer.steps.mail' => 'Email',
    'installer.steps.admin' => 'Administrator',
    'installer.admin.accent' => 'Accent colour of the forum',
    'installer.admin.accent_hint' => 'You can change it later under Administration, Site settings.',
];
