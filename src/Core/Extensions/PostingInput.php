<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** Immutable before-hook payload; actor and destination are never replaceable by an extension. */
final readonly class PostingInput
{
    public function __construct(
        public int $userId,
        public int $forumId,
        public string $content,
    ) {
        if (
            $userId < 1
            || $forumId < 1
            || $content === ''
            || strlen($content) > 262144
            || !mb_check_encoding($content, 'UTF-8')
        ) {
            throw new ExtensionFailure('extension_hook_input_invalid');
        }
    }
}
