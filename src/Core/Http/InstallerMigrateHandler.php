<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Closure;
use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Database\DatabaseHealthException;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Installer\SchemaMigrator;
use Nolviko\Core\Installer\SetupCode;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installer step 3 (M01.14): create the database tables. GET shows the status, POST (CSRF-checked, setup
 * code proven) runs a time-bounded batch of migration steps and redirects back, so refreshing is safe.
 * No JavaScript: the visitor presses the button again if one batch was not enough.
 */
final class InstallerMigrateHandler implements RouteHandler
{
    /**
     * @param Closure(): SchemaMigrator $schema Lazy: needs the database connection of a configured site.
     */
    public function __construct(
        private readonly InstallerPage $page,
        private readonly InstallationState $state,
        private readonly SetupCode $setupCode,
        private readonly Closure $schema,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->state === InstallationState::NotConfigured) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_DATABASE));
        }

        $diagnostics = [];

        try {
            if ($request->isMethod('POST')) {
                $code = $request->request->all()['setup_code'] ?? '';

                if (!$this->setupCode->isProven() && !$this->setupCode->prove(is_string($code) ? $code : '')) {
                    return $this->status(['installer_setup_code_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                ($this->schema)()->run();

                return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_MIGRATE));
            }
        } catch (MigrationException $failure) {
            $diagnostics = [$failure->diagnostic];
        } catch (DatabaseHealthException $failure) {
            $diagnostics = $failure->diagnostics();
        }

        return $this->status($diagnostics, Response::HTTP_OK);
    }

    /**
     * @param list<string> $diagnostics
     */
    private function status(array $diagnostics, int $status): Response
    {
        $pending = ($this->schema)()->pending();
        $this->setupCode->ensure();

        return $this->page->render('installer/migrate.twig', [
            'action' => $this->urls->generate(CoreRoutes::INSTALLER_MIGRATE),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'needs_code' => !$this->setupCode->isProven(),
            'code_file' => 'storage/' . SetupCode::FILE,
            'pending' => count($pending),
            'complete' => $pending === [],
            'admin_url' => $this->urls->generate(CoreRoutes::INSTALLER_MAIL),
            'errors' => $this->page->messages($diagnostics),
        ], $status);
    }
}
