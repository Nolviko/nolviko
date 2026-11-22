<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Http\ForumOptions;
use Nolviko\Modules\Moderation\Infrastructure\ModerationActions;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sticky, lock and move commands for one thread (M03.08). GET shows the form; POST (CSRF-checked by the kernel
 * guard) runs `command` with the optional target `forum`, `stub` = 1 and `reason` through ThreadCommands.
 * Members get 403, guests the login page, a thread the subject may not read a plain 404.
 */
final class ThreadCommandHandler implements RouteHandler
{
    private const STATUS = [
        'moderation_denied' => Response::HTTP_FORBIDDEN,
        'thread_state_unchanged' => Response::HTTP_CONFLICT,
        'thread_is_redirect' => Response::HTTP_CONFLICT,
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ThreadCommands $commands,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly ?ForumRepository $forums = null,
        private readonly ?ForumVisibility $visibility = null,
        private readonly ?ModerationActions $actions = null,
        private readonly ?ThreadRepository $threads = null,
        private readonly ?ThreadTaxonomy $taxonomy = null,
        private readonly ?CreateReply $replies = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $threadId = (int) ($parameters['thread'] ?? 0);
        // Move targets as a list of readable forums; ThreadCommands still checks the move permission on the target.
        $options = $this->forums === null || $this->visibility === null ? [] : ForumOptions::readable(
            $this->forums,
            $this->visibility->readable($this->subjects->of($request)),
        );

        if (!$request->isMethod('POST')) {
            return $this->page($threadId, null, '', $options);
        }

        $input = $request->request->all();
        $command = is_string($input['command'] ?? null) ? $input['command'] : '';
        $forum = is_string($input['forum'] ?? null) && ctype_digit($input['forum']) ? (int) $input['forum'] : null;
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';

        try {
            // beta.18 roadmap 40: `action:<id>` runs a saved moderation action step by step.
            if (str_starts_with($command, 'action:') && $this->actions !== null && $this->threads !== null) {
                $this->actions->apply(
                    $this->subjects->of($request),
                    $threadId,
                    (int) substr($command, 7),
                    $this->commands,
                    $this->threads,
                    $this->taxonomy,
                    $this->replies,
                    $request->getClientIp(),
                );
            } else {
                $this->commands->run(
                    $this->subjects->of($request),
                    $threadId,
                    $command,
                    $forum,
                    ($input['stub'] ?? '') === '1',
                    $reason,
                );
            }
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic === 'thread_not_found'
                ? $this->errors->notFound()
                : $this->page($threadId, $violation, $reason, $options);
        }

        // Back to the thread (a moved thread's old ID redirects to its new place).
        return InstallerPage::redirect(
            $this->urls->generate(CoreRoutes::THREAD_ID, ['thread' => (string) $threadId]),
        );
    }

    /** @param list<array{id: int, label: string, selectable: bool}> $options */
    private function page(int $threadId, ?ThreadRuleViolation $violation, string $reason, array $options): Response
    {
        $parameters = $violation?->diagnostic === 'post_reason_invalid' ? ['max' => EditPost::MAX_REASON_LENGTH] : [];
        $html = $this->views->render('threads/manage.twig', [
            'title' => $this->translator->translate('threads.manage.title'),
            'action' => $this->urls->generate(CoreRoutes::THREAD_MANAGE, ['thread' => (string) $threadId]),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'choices' => [
                'sticky' => ThreadCommands::STICKY,
                'unsticky' => ThreadCommands::UNSTICKY,
                'lock' => ThreadCommands::LOCK,
                'unlock' => ThreadCommands::UNLOCK,
                'move' => ThreadCommands::MOVE,
            ],
            'reason' => $reason,
            'saved_actions' => $this->actions?->all() ?? [],
            'forum_options' => $options,
            'posts_url' => $this->urls->generate(CoreRoutes::THREAD_POSTS, ['thread' => (string) $threadId]),
            'labels_url' => $this->urls->generate(CoreRoutes::THREAD_LABELS, ['thread' => (string) $threadId]),
            'max_reason' => EditPost::MAX_REASON_LENGTH,
            'error' => $violation === null
                ? null
                : $this->translator->translate('diagnostics.' . $violation->diagnostic, $parameters),
        ]);
        $status = $violation === null
            ? Response::HTTP_OK
            : self::STATUS[$violation->diagnostic] ?? Response::HTTP_UNPROCESSABLE_ENTITY;

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
