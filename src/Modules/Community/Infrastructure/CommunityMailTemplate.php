<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\MailMessage;
use Nolviko\Core\View\TwigFactory;

/** Localized notification mail uses the same escaped HTML/text template contract as account mail. */
final class CommunityMailTemplate
{
    public function __construct(
        private readonly string $root,
        private readonly string $storage,
    ) {
    }

    public function compose(string $locale, string $to, string $url): MailMessage
    {
        $translator = TranslatorFactory::create($this->root . '/translations', $locale);
        $views = TwigFactory::create($this->root, $this->storage, $translator);

        return (new MailComposer($views, $translator))->compose(
            'community_notification',
            'community.mail_subject',
            $to,
            ['url' => $url],
        );
    }
}
