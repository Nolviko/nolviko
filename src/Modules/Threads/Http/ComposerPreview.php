<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Threads\Domain\CreateThread;

/**
 * "Preview" button of the post forms (new thread, reply, edit): the typed BBCode rendered exactly as the thread page
 * will show it, by the same sanitizing renderer. Nothing is stored, no token is consumed and no hook runs; the form
 * is shown again with the text. Works without JavaScript (a plain submit with intent=preview).
 */
final class ComposerPreview
{
    public const INTENT = 'preview';

    /** @param array<string, mixed> $input */
    public static function requested(array $input): bool
    {
        return ($input['intent'] ?? '') === self::INTENT;
    }

    /** Null when the text is empty, too long or not UTF-8: the caller answers with `post_content_invalid`. */
    public static function html(MessageTranslator $translator, string $content): ?SafeHtml
    {
        if (
            trim($content) === '' || !mb_check_encoding($content, 'UTF-8')
            || mb_strlen($content) > CreateThread::MAX_CONTENT_LENGTH
        ) {
            return null;
        }

        return new SafeHtml(BbcodeRenderer::localize(
            (new BbcodeRenderer())->render($content),
            $translator->translate('core.post.spoiler'),
        ));
    }
}
