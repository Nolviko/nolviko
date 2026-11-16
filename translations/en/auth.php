<?php

declare(strict_types=1);

/*
 * English texts for login, logout and account pages (M02.04+).
 */

return [
    'auth.login.title' => 'Log in',
    'auth.login.identifier' => 'User name or email address',
    'auth.login.password' => 'Password',
    'auth.login.remember' => 'Stay logged in on this device',
    'auth.login.submit' => 'Log in',
    'auth.login.register' => 'No account yet? Register',
    'auth.login.resend' => 'Did not get the confirmation mail?',
    'auth.register.title' => 'Register',
    'auth.register.username' => 'User name (shown with your posts)',
    'auth.register.email' => 'Email address',
    'auth.register.password' => 'Password (at least {min, number, integer} characters)',
    'auth.register.confirmation' => 'Repeat the password',
    'auth.register.submit' => 'Create account',
    'auth.register.have_account' => 'Already have an account? Log in',
    'auth.register.sent_title' => 'Check your inbox',
    'auth.register.sent' => 'We sent a mail to the address you entered. Open the link in it within 24 hours to '
        . 'confirm your account. If the address already has an account, the mail tells you how to log in.',
    'auth.register.no_mail' => 'No mail after a few minutes? Ask for a new link',
    'auth.register.to_login' => 'Go to the login page',
    'auth.verify.title' => 'Confirm your email address',
    'auth.verify.confirm_intro' => 'Confirm your email address to activate your account.',
    'auth.verify.confirm' => 'Confirm my email address',
    'auth.verify.done' => 'Thank you. Your email address is confirmed and you can log in now.',
    'auth.verify.invalid' => 'This confirmation link is not valid. It may have expired or been used already.',
    'auth.verify.request_new' => 'Ask for a new confirmation link',
    'auth.resend.title' => 'New confirmation link',
    'auth.resend.intro' => 'Enter the address you registered with. If it has an account that is not confirmed '
        . 'yet, we send a new link; links are valid for 24 hours.',
    'auth.resend.submit' => 'Send a new link',
    'auth.register.website' => 'Leave this field empty',
    'auth.register.sent_open' => 'If this address is new, your account is ready and you can log in now. If it '
        . 'already had an account, we sent a mail to it.',
    'auth.register.closed_title' => 'Registration is closed',
    'auth.register.closed' => 'This forum does not accept new accounts at the moment.',
    'auth.challenge.sum' => 'To show you are a person: what is {first, number, integer} plus '
        . '{second, number, integer}?',
    'auth.verify.done_approval' => 'Thank you. Your email address is confirmed. An administrator reviews new '
        . 'accounts; you can log in once yours is approved.',
    'auth.login.forgot' => 'Forgot your password?',
    'auth.forgot.title' => 'Forgot your password',
    'auth.forgot.intro' => 'Enter the address of your account. If it has an active account, we send a link to '
        . 'choose a new password; the link is valid for one hour.',
    'auth.forgot.submit' => 'Send the link',
    'auth.forgot.sent' => 'If the address has an active account, a link is on its way.',
    'auth.reset.title' => 'Choose a new password',
    'auth.reset.submit' => 'Save the new password',
    'auth.reset.done' => 'Your password is changed. You were logged out everywhere; log in with the new password.',
    'auth.reset.invalid' => 'This link is not valid. It may have expired or been used already.',
    'auth.reset.request_new' => 'Ask for a new link',
    'auth.account.title' => 'Your account',
    'auth.account.signed_in_as' => 'Signed in as {name} ({email}).',
    'auth.account.current_password' => 'Current password',
    'auth.account.password_heading' => 'Change your password',
    'auth.account.password_submit' => 'Change password',
    'auth.account.password_changed' => 'Your password is changed. You were logged out on every other device.',
    'auth.account.email_heading' => 'Change your email address',
    'auth.account.new_email' => 'New email address',
    'auth.account.email_submit' => 'Send confirmation link',
    'auth.account.email_requested' => 'If the new address can be used, it got a link to confirm the change. Your '
        . 'old address stays until then.',
    'auth.email_confirm.title' => 'Confirm your new email address',
    'auth.email_confirm.intro' => 'Confirm to use this address for your account from now on.',
    'auth.email_confirm.submit' => 'Use this address',
    'auth.email_confirm.done' => 'Your account now uses the new email address.',
    'auth.email_confirm.invalid' => 'This link is not valid. It may have expired or been used already, or the '
        . 'address is no longer free.',
    'auth.resend.sent' => 'If the address has an unconfirmed account, a new link is on its way.',
    'auth.privacy.title' => 'Privacy and account deletion',
    'auth.privacy.policy' => 'New deletion requests have a {days, number} day cancellation period. After it ends, '
        . 'processing starts with a later site visit; without traffic it waits. You or an '
        . 'administrator can also complete it manually. Your account is removed and forum content'
        . ' keeps a removed author. Post text, quotes, security records and backups are not '
        . 'automatically erased.',
    'auth.privacy.scope' => 'Download includes your stored profile, authored threads/posts and messages in '
        . 'conversations you participate in. Other participants’ email addresses and unrelated '
        . 'conversations are excluded. Large accounts need assisted processing. Download before '
        . 'completing deletion.',
    'auth.privacy.admin_notice' => 'Administrator actions require recent password confirmation and '
        . 'are audited. Administrator downloads exclude private '
        . 'conversations; the account owner must download their own complete'
        . ' archive. Select the account carefully; completed deletion cannot'
        . ' be undone.',
    'auth.privacy.target' => 'Username',
    'auth.privacy.select' => 'Select account',
    'auth.privacy.selected' => 'Selected account: {id, number, integer}',
    'auth.privacy.pending' => 'Deletion can be completed from {date} UTC. Cancellation is available before this '
        . 'time.',
    'auth.privacy.confirm' => 'I understand the retention policy and confirm requesting or completing deletion.',
    'auth.privacy.export' => 'Download my data (ZIP)',
    'auth.privacy.request' => 'Request deletion',
    'auth.privacy.cancel' => 'Cancel deletion',
    'auth.privacy.finalize' => 'Complete deletion after the grace period',
    'auth.privacy.updated' => 'The account privacy request was updated.',
    'auth.privacy.due' => 'Deletion requests ready for confirmation',
    'auth.privacy.due_item' => 'Account {id, number, integer}: ready since {date} UTC',
    'auth.privacy.none_due' => 'No deletion requests are ready.',
    'auth.privacy.pending_auto' => 'Automatic deletion is queued for {date} UTC or a later site visit. You can '
        . 'cancel before this time.',
    'auth.privacy.processing' => 'Deletion is processing in durable batches. Login is disabled; this cannot be '
        . 'cancelled after the grace period.',
    'auth.privacy.export_pending' => 'Your archive is queued. It is collected in resumable steps as people visit the'
        . ' site. Refresh this page to see progress; no partial archive is downloadable.',
    'auth.privacy.export_help' => 'Use the export button to prepare or download the archive. Preparation continues '
        . 'without keeping this page open. When ready, enter your current password again to '
        . 'download. Collection spans multiple requests; records changed during that time '
        . 'may reflect different collection moments. Temporary archives expire after seven '
        . 'days and are cleaned during later processing.',
    'auth.privacy.export_status' => '{status, select, ready {Archive ready to download.} failed {Preparation '
        . 'stopped; use the export button to retry or ask the administrator for help.} '
        . 'other {Preparing archive.}} Collected records: {count, number}.',
    'auth.account.session_heading' => 'Session',
    'auth.account.logout' => 'Log out',
    'auth.account.logout_everywhere' => 'Log out on all devices',
    'auth.privacy.target_unknown' => 'No account has that username.',
    'auth.privacy.selected_name' => 'Selected account: {name} (number {id, number, integer})',
    'auth.register.work_status' => 'Checking that you are not a bot… this takes a moment.',
    'auth.logout.title' => 'Log out',
    'auth.logout.body' => 'Log out of your account on this device?',
    'auth.logout.cancel' => 'Stay signed in',
    'auth.login_verify.title' => 'Two-step verification',
    'auth.login_verify.intro' => 'Enter the code from your authenticator app to finish signing in.',
    'auth.login_verify.code' => 'Code',
    'auth.login_verify.recovery_hint' => 'No access to your phone? Enter one of your recovery codes instead.',
    'auth.login_verify.submit' => 'Verify',
    'auth.login_verify.cancel' => 'Cancel',
    'auth.account.second_factor_heading' => 'Two-step verification',
    'auth.account.second_factor_on' => 'On',
    'auth.account.second_factor_off' => 'Off',
    'auth.external.or' => 'or',
    'auth.external.title' => 'Sign in with another account',
    'auth.external.back_to_login' => 'Back to sign in',
    'auth.external.register_title' => 'Create your account',
    'auth.external.register_intro' => 'Your account will use the address {email}. Choose the name other members '
        . 'will see.',
    'auth.external.username' => 'User name',
    'auth.external.register_submit' => 'Create account',
    'auth.external.accounts_heading' => 'Linked accounts',
    'auth.external.linked' => 'Linked',
    'auth.external.link' => 'Link {provider}',
    'auth.external.unlink' => 'Unlink',
    'auth.external.unlink_help' => 'Unlinking needs your password. Created your account with an outside account? '
        . 'Set a password first with "Forgot password".',
    'auth.external.link_title' => 'Link to your account',
    'auth.external.link_intro' => 'There is already an account with {email}. Enter its password once to link your '
        . 'Google account; next time Google alone signs you in.',
    'auth.external.link_submit' => 'Link and sign in',
    'auth.external.link_forgot' => 'Forgot the password? Set a new one, then sign in with Google again.',
];
