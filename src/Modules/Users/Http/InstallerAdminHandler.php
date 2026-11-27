<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Installer\InstallLock;
use Nolviko\Core\Installer\InstallReport;
use Nolviko\Core\Installer\MailSetup;
use Nolviko\Core\Installer\SchemaMigrator;
use Nolviko\Core\Installer\SetupCode;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\ThemeTokens;
use Nolviko\Modules\Users\Domain\FirstAdminRejected;
use Nolviko\Modules\Users\Infrastructure\FirstAdminProvisioner;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installer step 4 (M02.15a): the first administrator, then the install lock. Reachable only while the site is
 * Configured and every migration has run; the POST (CSRF-checked) needs the proven setup code. When an
 * administrator already exists (an earlier attempt committed the account but could not write the lock) the step
 * only finishes the installation. After the lock the installer is gone (404) and the visitor goes to the login.
 * A ticked "report this installation" box (2026-11-27, on by default) sends one report after the lock.
 */
final class InstallerAdminHandler implements RouteHandler
{
    /**
     * @param Closure(): SchemaMigrator $schema
     * @param Closure(): FirstAdminProvisioner $provisioner Both lazy: they need the database of a configured site.
     */
    public function __construct(
        private readonly InstallerPage $page,
        private readonly InstallationState $state,
        private readonly SetupCode $setupCode,
        private readonly Closure $schema,
        private readonly Closure $provisioner,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly string $configDirectory,
        private readonly ?MailSetup $mailSetup = null,
        private readonly ?Closure $siteSettings = null,
        private readonly ?InstallReport $report = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->state === InstallationState::NotConfigured) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_DATABASE));
        }

        if (($this->schema)()->pending() !== []) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_MIGRATE));
        }

        $provisioner = ($this->provisioner)();
        $input = $request->request->all();
        $username = is_string($input['username'] ?? null) ? $input['username'] : '';
        $email = is_string($input['email'] ?? null) ? $input['email'] : '';

        $host = $request->getHost();

        if (!$request->isMethod('POST')) {
            return $this->form($provisioner->hasAdministrator(), $username, $email, [], Response::HTTP_OK, host: $host);
        }

        $reportChecked = ($input['report_install'] ?? '') === '1';

        $code = is_string($input['setup_code'] ?? null) ? $input['setup_code'] : '';

        if (!$this->setupCode->isProven() && !$this->setupCode->prove($code)) {
            return $this->form(
                false,
                $username,
                $email,
                ['installer_setup_code_invalid'],
                422,
                'green',
                $host,
                $reportChecked,
            );
        }

        try {
            if (!$provisioner->hasAdministrator()) {
                $adminId = $provisioner->provision(
                    $username,
                    $email,
                    is_string($input['password'] ?? null) ? $input['password'] : '',
                    is_string($input['password_confirmation'] ?? null) ? $input['password_confirmation'] : '',
                );
            }

            // The accent colour chosen here (2026-11-08); /admin/site changes it later. Never blocks the install.
            $accent = is_string($input['accent'] ?? null) ? $input['accent'] : '';

            if (isset($adminId) && $this->siteSettings !== null && isset(ThemeTokens::ACCENTS[$accent])) {
                ($this->siteSettings)()->save(['appearance.accent' => $accent], [], $adminId);
            }

            $this->mailSetup?->ensureSender($provisioner->administratorEmail());
            InstallLock::write($this->configDirectory);
        } catch (FirstAdminRejected $rejected) {
            return $this->form(
                false,
                $username,
                $email,
                $rejected->diagnostics,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                is_string($input['accent'] ?? null) ? $input['accent'] : 'green',
                $host,
                $reportChecked,
            );
        } catch (RuntimeException $failure) {
            return $this->form(
                true,
                $username,
                $email,
                [$failure->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'green',
                $host,
                $reportChecked,
            );
        }

        $this->setupCode->remove();

        if ($reportChecked) {
            $this->report?->send($host);
        }

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
    }

    /**
     * @param list<string> $diagnostics
     */
    private function form(
        bool $finishOnly,
        string $username,
        string $email,
        array $diagnostics,
        int $status,
        string $accent = 'green',
        string $host = '',
        bool $reportChecked = true,
    ): Response {
        $this->setupCode->ensure();
        $version = $this->report?->version();
        $domain = InstallReport::domain($host);

        return $this->page->render('installer/admin.twig', [
            'action' => $this->urls->generate(CoreRoutes::INSTALLER_ADMIN),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'needs_code' => !$this->setupCode->isProven(),
            'code_file' => 'storage/' . SetupCode::FILE,
            'finish_only' => $finishOnly,
            'username' => $username,
            'email' => $email,
            'accents' => array_keys(ThemeTokens::ACCENTS),
            'accent' => $accent,
            'report' => $version !== null && $domain !== null
                ? ['domain' => $domain, 'version' => $version, 'checked' => $reportChecked] : null,
            'errors' => $this->page->messages($diagnostics),
        ], $status);
    }
}
