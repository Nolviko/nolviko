<?php

declare(strict_types=1);

namespace Nolviko\Core\Mail;

use InvalidArgumentException;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\View\TemplateRenderer;

/**
 * Builds a MailMessage from `views/mail/<name>.html.twig` and `<name>.txt.twig` plus a translated subject
 * (M02.02a; callers pass the subject's message ID, for example `mail.test.subject`). Both bodies go through
 * the normal autoescaping Twig environment (the template policy allows no unescaped output); the text body is
 * entity-decoded afterwards, which restores exactly the values passed in, since plain text has no markup to
 * protect.
 */
final class MailComposer
{
    private const NAME = '/^[a-z][a-z0-9_]*$/D';

    public function __construct(
        private readonly TemplateRenderer $views,
        private readonly MessageTranslator $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $context Template variables; `subject` parameters are taken from it as well.
     */
    public function compose(string $name, string $subjectId, string $to, array $context = []): MailMessage
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException('mail_template_invalid');
        }

        $subjectParameters = array_filter(
            $context,
            static fn(mixed $value): bool => is_string($value) || is_int($value),
        );
        $subject = $this->translator->translate($subjectId, $subjectParameters);
        $context += ['language' => str_replace('_', '-', $this->translator->locale()), 'subject' => $subject];
        $text = $this->views->render('mail/' . $name . '.txt.twig', $context);

        return new MailMessage(
            $to,
            // A subject is one header line: control characters, including line breaks, are dropped.
            trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $subject)),
            html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $this->views->render('mail/' . $name . '.html.twig', $context),
        );
    }
}
