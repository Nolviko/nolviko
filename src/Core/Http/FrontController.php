<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Closure;
use Nolviko\Core\Application\CompositionRoot;
use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Application\VisitorTasks;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Container\Container;
use Nolviko\Core\Container\ContainerException;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Logging\IpPolicy;
use Nolviko\Core\Logging\JsonFileLogger;
use Nolviko\Core\Logging\LoggerReporter;
use Nolviko\Core\Logging\Redactor;
use Nolviko\Core\Runtime\Bootstrap;
use Nolviko\Core\Runtime\RuntimeRequirementsException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Composition behind public/index.php: configuration, boot, then route, and always end in a Response.
 *
 * Failure order: an unusable configuration or a runtime-policy failure is a 503 listing diagnostics;
 * anything else unexpected is a 500 with an incident ID. Every outcome, including those, gets the security
 * headers (SecurityHeaders) with this request's CSP nonce. The locale is the English fallback until ADR-015
 * decides locale selection.
 */
final class FrontController
{
    /** Overrides the private storage directory; default `<project>/storage` (ZIP layout). */
    public const STORAGE_ENVIRONMENT = 'FORUM_STORAGE_DIR';

    /** Database driver checked by the runtime policy before a configuration exists. */
    public const DRIVER_ENVIRONMENT = 'DB_DRIVER';

    public const DEFAULT_DRIVER = 'mysql';

    public const LOG_DIRECTORY = 'logs';

    /** Forwarded headers honoured from trusted proxies; X-Forwarded-Host is not (no trusted-host list yet). */
    public const FORWARDED_HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT;

    /**
     * Handle the current HTTP request from PHP globals and send the response.
     */
    public static function run(string $projectDirectory): void
    {
        $request = null;
        $response = self::respond(
            $projectDirectory,
            static function () use (&$request): Request {
                $request = Request::createFromGlobals();

                return $request;
            },
        );

        if ($request !== null) {
            $response->prepare($request);
        }

        $response->send();

        if ($request?->attributes->get('_defer_visitor_tasks') === true
            && !function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) {
            return;
        }

        // Not after a server error. 4xx and 503 still tick: a crawler asking for a sitemap that is being built
        // (503) is often the only visitor of a quiet site.
        if ($response->getStatusCode() < 500 || $response->getStatusCode() === 503) {
            VisitorTasks::tick($projectDirectory);
        }
    }

    /**
     * @param Closure(): Request $createRequest Called only after Bootstrap has disabled method overrides.
     * @param ErrorReporter|null $reporter Null means the JSON log in `<storage>/logs/` (LoggerReporter).
     * @param array<string, string>|null $environment Null means getenv().
     * @param string|null $configDirectory Holds local.php and install.lock; null means `<project>/config`.
     */
    public static function respond(
        string $projectDirectory,
        Closure $createRequest,
        ?ErrorReporter $reporter = null,
        ?array $environment = null,
        ?string $configDirectory = null,
    ): Response {
        $nonce = CspNonce::generate();
        $request = null;
        $config = null;
        $response = self::dispatch(
            $projectDirectory,
            static function () use ($createRequest, &$request): Request {
                $request = $createRequest();

                return $request;
            },
            $nonce,
            $config,
            $reporter,
            $environment ?? getenv(),
            $configDirectory ?? $projectDirectory . '/config',
        );

        return (new SecurityHeaders($nonce, $config?->hsts ?? false))->apply($response, $request);
    }

    /**
     * @param Closure(): Request $createRequest
     * @param Config|null $config Set to the loaded configuration, so headers can follow it on every outcome.
     * @param array<string, string> $environment
     */
    private static function dispatch(
        string $projectDirectory,
        Closure $createRequest,
        CspNonce $nonce,
        ?Config &$config,
        ?ErrorReporter $reporter,
        array $environment,
        string $configDirectory,
    ): Response {
        $translator = null;
        self::trustProxies(null);

        try {
            $state = InstallationState::detect($configDirectory);
            $config = ConfigLoader::load($configDirectory . '/' . InstallationState::CONFIG_FILE, $environment);
            $root = Bootstrap::boot($projectDirectory, self::driver($config, $environment))->projectDirectory;
        } catch (ConfigException $failure) {
            return self::unavailable($projectDirectory, $failure->diagnostics());
        } catch (RuntimeRequirementsException $failure) {
            return self::unavailable($projectDirectory, $failure->errors());
        } catch (Throwable $error) {
            $logger = self::logger($projectDirectory, $environment, $config);

            return self::failure($error, null, $reporter ?? new LoggerReporter($logger));
        }

        self::trustProxies($config);
        $logger = self::logger($root, $environment, $config);
        $reporter ??= new LoggerReporter($logger);

        try {
            $services = self::services(
                $root,
                $config,
                $state,
                $reporter,
                $environment,
                $logger,
                $nonce,
                $configDirectory,
            );
            $translator = $services->service(MessageTranslator::class);

            return $services->service(Kernel::class)->handle($createRequest());
        } catch (Throwable $error) {
            return self::failure($error, $translator, $reporter);
        }
    }

    /**
     * The validated service container for one request (after configuration and Bootstrap).
     *
     * @param array<string, string> $environment
     *
     * @throws ContainerException When the wiring is invalid.
     */
    public static function services(
        string $root,
        ?Config $config,
        InstallationState $state,
        ErrorReporter $reporter,
        array $environment,
        ?LoggerInterface $logger = null,
        ?CspNonce $nonce = null,
        ?string $configDirectory = null,
    ): Container {
        return CompositionRoot::build(
            $root,
            self::storageDirectory($root, $environment),
            $config,
            $state,
            $reporter,
            $logger,
            $nonce,
            configDirectory: $configDirectory,
        );
    }

    /**
     * Only configured proxies may vouch for HTTPS, port and client address (default none). Reset first on every
     * request, so a failed configuration never inherits an earlier trust list in the same process.
     */
    private static function trustProxies(?Config $config): void
    {
        Request::setTrustedProxies($config?->trustedProxies ?? [], self::FORWARDED_HEADERS);
    }

    /**
     * JSON lines in `<storage>/logs/`, scrubbing the configured secrets, with the configured IP policy.
     *
     * @param array<string, string> $environment
     */
    public static function logger(string $root, array $environment, ?Config $config): LoggerInterface
    {
        $secrets = $config === null
            ? []
            : [$config->databasePassword, $config->appSecret, $config->mail->smtpPassword,
                ...array_column($config->oauth, 'client_secret')];

        return new JsonFileLogger(
            self::storageDirectory($root, $environment) . '/' . self::LOG_DIRECTORY,
            new Redactor($secrets),
            $config?->ipLogging ?? IpPolicy::Truncated,
        );
    }

    /**
     * @param array<string, string> $environment
     */
    private static function storageDirectory(string $root, array $environment): string
    {
        return ($environment[self::STORAGE_ENVIRONMENT] ?? '') ?: $root . '/storage';
    }

    /**
     * The configured driver; before installation the environment's (Docker) or the default.
     *
     * @param array<string, string> $environment
     */
    private static function driver(?Config $config, array $environment): string
    {
        return $config?->databaseDriver ?? (($environment[self::DRIVER_ENVIRONMENT] ?? '') ?: self::DEFAULT_DRIVER);
    }

    /**
     * @param list<string> $diagnostics
     */
    private static function unavailable(string $projectDirectory, array $diagnostics): Response
    {
        return (new ErrorResponder(self::translatorOrNull($projectDirectory)))->unavailable($diagnostics);
    }

    private static function translator(string $root): MessageTranslator
    {
        return TranslatorFactory::create($root . '/translations', TranslatorFactory::FALLBACK_LOCALE);
    }

    /**
     * The 503 page must render even when intl or the catalogs are what is broken.
     */
    private static function translatorOrNull(string $projectDirectory): ?MessageTranslator
    {
        try {
            return self::translator($projectDirectory);
        } catch (Throwable) {
            return null;
        }
    }

    private static function failure(Throwable $error, ?MessageTranslator $translator, ErrorReporter $reporter): Response
    {
        $incidentId = IncidentId::generate();
        $reporter->report($incidentId, $error);

        return (new ErrorResponder($translator))->serverError($incidentId);
    }
}
