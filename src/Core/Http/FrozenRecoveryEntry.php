<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Database\RecoveryDispatcher;
use Nolviko\Core\Database\UpdateJournal;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Runtime\Bootstrap;
use Nolviko\Core\Session\NativeSession;
use Nolviko\Core\Updates\RecoveryGrantStore;
use Nolviko\Core\Updates\UpdateSmokeCheck;
use Nolviko\Modules\Admin\Http\AdminGuard;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Bootstrap from the frozen bundle, or the fully verified target for post-switch commands. */
final class FrozenRecoveryEntry
{
    /** @param array<string, string> $environment */
    public static function respond(
        string $runtime,
        string $site,
        string $storage,
        string $recovery,
        string $origin,
        array $environment,
    ): Response {
        try {
            $config = ConfigLoader::load($site . '/config/local.php', $environment);

            if ($config === null || InstallationState::detect($site . '/config') !== InstallationState::Installed) {
                return self::unavailable();
            }

            Request::setTrustedProxies($config->trustedProxies, Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT);
            $request = Request::createFromGlobals();
            $environment['FORUM_STORAGE_DIR'] = $storage;
            $reporter = new class implements ErrorReporter {
                public function report(string $incidentId, Throwable $error, array $context = []): void
                {
                    // Recovery never logs credential-bearing request objects or raw exception details.
                }
            };
            $services = FrontController::services(
                $runtime,
                $config,
                InstallationState::Installed,
                $reporter,
                $environment,
                configDirectory: $site . '/config',
            );
            $session = $services->get(NativeSession::class);
            $session->begin($request);

            try {
                $subjects = $services->get(RequestSubject::class);
                $admin = $services->get(AdminSession::class);
                $connection = $services->get(\Doctrine\DBAL\Connection::class);
                $journal = new UpdateJournal($connection);
                $targetRuntime = realpath($runtime) === realpath($site);
                $smoke = $targetRuntime ? new UpdateSmokeCheck($site, [
                    'runtime' => static fn(): bool => Bootstrap::boot($site, $config->databaseDriver)
                        ->projectDirectory === realpath($site),
                    'routing' => static function () use ($services, $origin): bool {
                        $route = $services->get(Router::class)->match(Request::create($origin . '/admin/updates'));

                        return ($route['_route'] ?? null) === CoreRoutes::ADMIN_UPDATES;
                    },
                    'database' => static fn(): bool => (int) $connection->fetchOne('SELECT 1') === 1,
                ]) : null;
                $dispatcher = new RecoveryDispatcher(
                    $connection,
                    $journal,
                    $site,
                    $recovery,
                    $config->databaseDriver,
                    $targetRuntime,
                    $smoke,
                );
                $gateway = new RecoveryGateway(
                    new RecoveryGrantStore($recovery . '/grants', $site . '/public'),
                    $origin,
                    static fn(Request $incoming, int $actor): bool => $subjects->userId($incoming) === $actor
                        && $subjects->of($incoming)->allows(AdminGuard::PERMISSION) && $admin->isConfirmed(),
                    $dispatcher->actions(),
                );
                $response = $gateway->handle($request, []);
                $services->get(ResponseCookies::class)->apply($response);

                return $session->commit($response);
            } catch (Throwable) {
                return $session->commit(self::unavailable());
            }
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    private static function unavailable(): Response
    {
        return new Response('{"error":"recovery_unavailable"}', 503, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
