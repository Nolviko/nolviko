<?php

declare(strict_types=1);

/*
 * English texts of outgoing mail (M02.02a+). Subjects are one line; bodies are rendered as plain text and HTML.
 */

return [
    'mail.footer' => 'This message was sent automatically by the forum. Please do not reply to it.',
    'mail.test.subject' => 'Test message from your forum',
    'mail.test.greeting' => 'Hello {name},',
    'mail.test.body' => 'This is a test message. If you can read it, the forum can send mail.',
    'mail.verify_email.subject' => 'Confirm your email address',
    'mail.verify_email.greeting' => 'Hello {name},',
    'mail.verify_email.body' => 'Thank you for registering. Open this link to confirm your email address and '
        . 'activate your account:',
    'mail.verify_email.button' => 'Confirm my email address',
    'mail.verify_email.expiry' => 'The link is valid for 24 hours and can be used once.',
    'mail.verify_email.not_you' => 'If you did not register, ignore this mail; no account is activated without '
        . 'the link.',
    'mail.password_reset.subject' => 'Choose a new password',
    'mail.password_reset.greeting' => 'Hello {name},',
    'mail.password_reset.body' => 'Someone asked for a new password for your account. Open this link to choose '
        . 'one:',
    'mail.password_reset.button' => 'Choose a new password',
    'mail.password_reset.expiry' => 'The link is valid for one hour and can be used once. Choosing a new password '
        . 'logs you out on every device.',
    'mail.password_reset.not_you' => 'If you did not ask for this, ignore this mail; your password stays as it is.',
    'mail.email_change.subject' => 'Confirm your new email address',
    'mail.email_change.greeting' => 'Hello {name},',
    'mail.email_change.body' => 'Open this link to use this address for your forum account:',
    'mail.email_change.button' => 'Use this address',
    'mail.email_change.expiry' => 'The link is valid for 24 hours and can be used once.',
    'mail.email_change.not_you' => 'If you did not ask for this, ignore this mail; nothing changes.',
    'mail.email_change_notice.subject' => 'Your email address is about to change',
    'mail.email_change_notice.greeting' => 'Hello {name},',
    'mail.email_change_notice.body' => 'Someone signed in to your account asked to move it to another email '
        . 'address. The change happens only when the link sent to the new address is opened.',
    'mail.email_change_notice.not_you' => 'If this was not you, change your password now:',
    'mail.already_registered.subject' => 'Someone tried to register with your email address',
    'mail.already_registered.greeting' => 'Hello {name},',
    'mail.already_registered.body' => 'Someone tried to create a new account with this email address, which '
        . 'already belongs to your account. No second account was created.',
    'mail.already_registered.login' => 'If it was you, log in here:',
    'mail.already_registered.resend' => 'If you never confirmed your address, ask for a new link here:',
    'mail.already_registered.not_you' => 'If it was not you, you do not need to do anything.',
    'mail.second_factor.subject' => 'Two-step verification changed',
    'mail.second_factor.enabled' => 'Two-step verification is now on for your account.',
    'mail.second_factor.disabled' => 'Two-step verification is now off for your account.',
    'mail.second_factor.reset' => 'An administrator turned off two-step verification for your account.',
    'mail.second_factor.not_you' => 'If this was not you, change your password and check your account:',
];
