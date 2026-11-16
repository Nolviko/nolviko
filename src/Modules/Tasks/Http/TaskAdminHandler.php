<?php

declare(strict_types=1);

namespace Nolviko\Modules\Tasks\Http;

use InvalidArgumentException;
use Nolviko\Core\Application\TaskConsent;
use Nolviko\Core\Application\TaskRuntime;
use Nolviko\Core\Cache\RedisOptions;
use Nolviko\Core\Cache\RedisSettingsStore;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Tasks\Infrastructure\TaskAdministration;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Fresh-admin/CSRF gates precede any worker, retry or cache-configuration command. No payload editor. */
final class TaskAdminHandler implements RouteHandler
{
    public function __construct(
        private readonly TaskAdministration $tasks,
        private readonly TaskRuntime $runtime,
        private readonly TaskConsent $consent,
        private readonly RedisSettingsStore $redis,
        private readonly RequestSubject $subjects,
        private readonly AdminSession $admin,
        private readonly CsrfTokens $csrf,
        private readonly DbalAdminAudit $audit,
        private readonly TemplateRenderer $views,
        private readonly MessageTranslator $translator,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $route = $parameters['_route'];
        $error = null;
        $notice = null;
        $status = 200;
        $items = [];
        $summary = ['failed' => 0, 'overdue' => 0, 'expired' => 0];
        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $filter = $request->isMethod('POST') ? '' : $text('status');
        $before = 0;

        try {
            if ($subject->userId === null || !$subject->allows('admin.access')) {
                throw new InvalidArgumentException('tasks_denied');
            }

            $before = $text('before') === '' ? 0 : self::id($text('before'));

            if ($request->isMethod('POST')) {
                if (!$this->admin->isFresh() || !$this->csrf->isValid($text(CsrfTokens::FIELD))) {
                    throw new InvalidArgumentException('tasks_denied');
                }

                $action = $text('action');
                $allowed = $route === CoreRoutes::ADMIN_TASKS
                    ? ['run', 'retry', 'enable_cli', 'disable_cli'] : ['preflight', 'enable', 'disable'];

                if (!in_array($action, $allowed, true)) {
                    throw new InvalidArgumentException('tasks_action_invalid');
                }

                $this->audit->record($subject->userId, 'admin.tasks_' . $action . '_requested');

                if ($action === 'run') {
                    $result = $this->runtime->run(3, 2000);
                    $notice = $this->translator->translate('tasks.run_result', $result);
                } elseif ($action === 'retry') {
                    $this->tasks->retry(self::id($text('id')));
                } elseif (in_array($action, ['enable_cli', 'disable_cli'], true)) {
                    $this->consent->set($action === 'enable_cli');
                } elseif ($action === 'disable') {
                    $this->redis->disable();
                } else {
                    $namespaces = $input['namespaces'] ?? [];

                    if (!is_array($namespaces) || array_filter($namespaces, is_array(...)) !== []) {
                        throw new InvalidArgumentException('redis_options_invalid');
                    }

                    $options = new RedisOptions(
                        $text('host'),
                        self::id($text('port')),
                        $text('password'),
                        $text('username'),
                        $text('database') === '' ? 0 : self::id($text('database')),
                        $text('tls') === '1',
                        $text('peer_name'),
                        $namespaces
                    );

                    if ($action === 'enable') {
                        $this->redis->activate($options);
                    } else {
                        $this->redis->preflight($options);
                    }
                }

                $this->audit->record($subject->userId, 'admin.tasks_' . $action . '_completed');
                $notice ??= $this->translator->translate('tasks.updated');
            }

            $items = $this->tasks->page($filter, $before);
            $summary = $this->tasks->summary();
        } catch (InvalidArgumentException $failure) {
            $status = $failure->getMessage() === 'tasks_denied' ? 403 : 422;
            $error = $this->translator->translate($status === 403 ? 'tasks.denied' : 'tasks.invalid');
        } catch (Throwable) {
            $status = 503;
            $error = $this->translator->translate('tasks.unavailable');
        }

        $more = count($items) > 50;
        $items = array_slice($items, 0, 50);
        $title = $this->translator->translate($route === CoreRoutes::ADMIN_TASKS ? 'tasks.title' : 'tasks.cache');

        return new Response($this->views->render('admin/tasks.twig', [
            'title' => $title,
            'nav' => AdminNavigation::items($this->urls, $this->translator, $route),
            'cache_mode' => $route === CoreRoutes::ADMIN_CACHE,
            'items' => $items, 'summary' => $summary, 'filter' => $filter,
            'redis' => $status === 403 ? ['enabled' => false, 'reason' => null] : $this->redis->status(),
            'cli_enabled' => $status !== 403 && $this->consent->enabled(),
            'error' => $error, 'notice' => $notice,
            'action' => $this->urls->generate($route),
            'older' => !$more ? null : $this->urls->generate($route) . '?'
                . http_build_query(['status' => $filter, 'before' => $items[49]['id']]),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    private static function id(string $value): int
    {
        if (preg_match('/^[0-9]{1,18}$/D', $value) !== 1) {
            throw new InvalidArgumentException('tasks_action_invalid');
        }

        return (int) $value;
    }
}
