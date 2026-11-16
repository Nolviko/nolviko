<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;

/** Synchronous, typed, ordered and bounded hooks; failures deny the write and cannot bypass core validation. */
final class BeforeHooks
{
    /** @var array<string, Closure(PostingInput): HookDecision> */
    private array $posting = [];

    public function add(string $owner, Closure $hook): void
    {
        if (isset($this->posting[$owner]) || count($this->posting) >= 32) {
            throw new ExtensionFailure('extension_hook_duplicate');
        }

        $this->posting[$owner] = $hook;
    }

    public function posting(PostingInput $input): string
    {
        $content = $input->content;
        foreach ($this->posting as $owner => $hook) {
            $start = hrtime(true);
            $decision = $hook(new PostingInput($input->userId, $input->forumId, $content));
            if (!$decision instanceof HookDecision || hrtime(true) - $start > 50000000) {
                throw new ExtensionFailure('extension_hook_failed');
            }

            if ($decision->reason !== null) {
                if (
                    !str_starts_with($decision->reason, str_replace('/', '.', $owner) . '.')
                    || preg_match('/^[a-z][a-z0-9_.]{1,255}$/D', $decision->reason) !== 1
                ) {
                    throw new ExtensionFailure('extension_namespace_invalid');
                }

                throw new ExtensionFailure('extension_hook_denied:' . $decision->reason);
            }

            if ($decision->replacement !== null) {
                $content = (new PostingInput($input->userId, $input->forumId, $decision->replacement))->content;
            }
        }

        return $content;
    }
}
