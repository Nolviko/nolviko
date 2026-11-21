<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Closure;
use Nolviko\Core\Database\UpdatePreparation;
use Nolviko\Core\Extensions\ExtensionManager;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Throwable;

/**
 * Notices shown at the top of every admin page (beta.17, owner request): safe mode left on, with a button that
 * tries the extensions again, and a newer release with a link to the update page. Read only when an admin page is
 * rendered. The release check uses the core's cached discovery (GitHub at most hourly, with failure back-off), so
 * administrators no longer have to look for updates themselves.
 */
final class AdminNotices
{
    /** @var list<array<string, mixed>>|null */
    private ?array $notices = null;

    /**
     * @param Closure(): ?UpdatePreparation $updates Null when updates are not configured on this site.
     */
    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly Closure $updates,
        private readonly UrlGenerator $urls,
        private readonly CsrfTokens $csrf,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->notices !== null) {
            return $this->notices;
        }

        $notices = [];

        try {
            if ($this->extensions->state->safe()) {
                $notices[] = [
                    'kind' => 'safe',
                    'safe' => true,
                    'confirm' => '1',
                    'failure' => $this->extensions->state->hasBootFailure(),
                    'action' => $this->urls->generate(CoreRoutes::ADMIN_EXTENSIONS),
                    'csrf_field' => CsrfTokens::FIELD,
                    'csrf_token' => $this->csrf->token(),
                    'value' => 'resume',
                ];
            }
        } catch (Throwable) {
            // A notice never breaks the admin page.
        }

        try {
            $updates = ($this->updates)();
            $tag = $updates !== null && $updates->configured() ? $updates->available() : null;
            if ($tag !== null) {
                $notices[] = ['kind' => 'update', 'safe' => false, 'version' => $tag,
                    'url' => $this->urls->generate(CoreRoutes::ADMIN_UPDATES)];
            }
        } catch (Throwable) {
            // Discovery problems are shown on the update page itself.
        }

        return $this->notices = $notices;
    }
}
