<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Installer\DatabaseSetup;
use Nolviko\Core\Installer\SetupCode;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installer step 2 (M01.14): setup code and database settings. POST is CSRF-checked by CsrfGuard; the
 * settings are tested before `config/local.php` is written; once written, this step sends visitors on to
 * the migration step (delete the file by hand to enter other settings).
 */
final class InstallerDatabaseHandler implements RouteHandler
{
    private const FIELDS = ['driver', 'host', 'port', 'name', 'user'];

    public function __construct(
        private readonly InstallerPage $page,
        private readonly InstallationState $state,
        private readonly SetupCode $setupCode,
        private readonly DatabaseSetup $setup,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->state !== InstallationState::NotConfigured) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_MIGRATE));
        }

        if (!$request->isMethod('POST')) {
            return $this->form([], [], Response::HTTP_OK);
        }

        $input = $request->request->all();

        if (!$this->setupCode->isProven() && !$this->setupCode->prove(self::text($input, 'setup_code'))) {
            return $this->form($input, ['installer_setup_code_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The installer is the one trusted moment to learn the site address (the person installing typed it).
        $diagnostics = $this->setup->configure($input, $request->getSchemeAndHttpHost() . $request->getBasePath());

        if ($diagnostics !== []) {
            return $this->form($input, $diagnostics, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_MIGRATE));
    }

    /**
     * @param array<string, mixed> $input Previous submission; the password is never sent back.
     * @param list<string> $diagnostics
     */
    private function form(array $input, array $diagnostics, int $status): Response
    {
        $storageReady = $this->setupCode->ensure();
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = self::text($input, $field);
        }

        return $this->page->render('installer/database.twig', [
            'action' => $this->urls->generate(CoreRoutes::INSTALLER_DATABASE),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'needs_code' => !$this->setupCode->isProven(),
            'code_file' => 'storage/' . SetupCode::FILE,
            'storage_ready' => $storageReady,
            'values' => $values,
            'errors' => $this->page->messages($diagnostics),
        ], $status);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function text(array $input, string $field): string
    {
        return is_string($input[$field] ?? null) ? $input[$field] : '';
    }
}
