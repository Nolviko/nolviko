<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\TransportPolicy;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Conversations\Domain\ConversationRefused;
use Nolviko\Modules\Conversations\Infrastructure\PrivateConversations;
use Nolviko\Modules\Conversations\Infrastructure\PrivateDrafts;
use Nolviko\Modules\Conversations\Infrastructure\PrivateFolders;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Editor\Domain\MarkdownToBbcode;
use Nolviko\Modules\Importers\Infrastructure\PrivateImportFiles;
use Nolviko\Modules\Threads\Http\ComposerPreview;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticated participant-only SSR messaging; no user ID is accepted as the author.
 *
 * Beta 17 G1: `/conversations` also lists a folder (`?folder=`), the drafts (`?view=drafts`) and the folder
 * manager (`?view=folders`), and takes POST `action` = folder_create | folder_rename | folder_delete |
 * draft_delete. The composer saves drafts (`intent=draft`) and continues one (`?draft=`). A conversation takes POST
 * `action` = remove | file | unfile besides replies. Every POST checks CSRF; every query is scoped to the actor.
 */
final class ConversationsHandler implements RouteHandler
{
    public const INTENT_DRAFT = 'draft';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly PrivateConversations $conversations,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ?PrivateImportFiles $importedFiles = null,
        private readonly ?PrivateFolders $folders = null,
        private readonly ?PrivateDrafts $drafts = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if (!TransportPolicy::allows($request)) {
            return new Response(
                $this->translator->translate('diagnostics.privacy_https_required'),
                403,
                self::headers(),
            );
        }

        $actor = $this->subjects->of($request)->userId;

        if ($actor === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $route = $parameters['_route'];
        $id = isset($parameters['conversation']) ? (int) $parameters['conversation'] : null;
        $input = $request->request->all();

        if (($input['format'] ?? '') === MarkdownToBbcode::FORMAT && is_string($input['content'] ?? null)) {
            $input['content'] = MarkdownToBbcode::convert($input['content']);
        }

        $field = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $query = $request->query->all();
        $number = static fn(mixed $value): int|false|null => is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        $before = $number($query['before'] ?? null);
        $folder = $number($query['folder'] ?? null);
        $draft = $number($input['draft'] ?? ($query['draft'] ?? null));
        $view = is_string($query['view'] ?? null) ? $query['view'] : '';
        $context = ['error' => null, 'notice' => null, 'subject' => '', 'content' => '', 'recipient' => '',
            'hidden_recipients' => '', 'conversation' => null, 'items' => [], 'older' => null, 'preview' => null,
            'preview_intent' => ComposerPreview::INTENT, 'draft_intent' => self::INTENT_DRAFT, 'draft' => null,
            'view' => in_array($view, ['drafts', 'folders'], true) ? $view : 'inbox', 'folder' => null,
            'folders' => [], 'drafts' => [], 'max_recipients' => $this->conversations->maxRecipients()];
        $status = 200;

        // `?to=` from a profile's "Send message" fills in the recipient (2026-11-09).
        if (!$request->isMethod('POST') && is_string($query['to'] ?? null)) {
            $input['recipient'] = $query['to'];
        }

        $limits = ['subject' => 160, 'recipient' => 1000, 'hidden_recipients' => 1000, 'content' => 8000];

        foreach ($limits as $name => $limit) {
            $value = $field($name);
            $context[$name] = mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, $limit, 'UTF-8') : '';
        }

        try {
            if ($before === false || $folder === false || $draft === false) {
                throw new RegistrationRejected(['conversation_not_found']);
            }

            if ($request->isMethod('POST') && ComposerPreview::requested($input)) {
                // 2026-11-08: BBCode preview, nothing is sent.
                $context['preview'] = ComposerPreview::html($this->translator, $field('content'));
                $context['draft'] = $draft;
            } elseif ($request->isMethod('POST')) {
                if (!$this->csrf->isValid($field(CsrfTokens::FIELD))) {
                    return new Response(
                        $this->translator->translate('diagnostics.privacy_denied'),
                        403,
                        self::headers(),
                    );
                }

                return InstallerPage::redirect($this->write($route, $actor, $id, $draft, $field));
            } elseif ($route === CoreRoutes::CONVERSATIONS_NEW && $draft !== null && $this->drafts !== null) {
                $saved = $this->drafts->find($actor, $draft);

                if ($saved === null) {
                    throw new ConversationRefused('conversation_draft_not_found');
                }

                $context['draft'] = (int) $saved['id'];
                $context['recipient'] = (string) $saved['recipients'];
                $context['hidden_recipients'] = (string) $saved['hidden_recipients'];
                $context['subject'] = (string) $saved['subject'];
                $context['content'] = (string) $saved['content'];
                $context['notice'] = isset($query['saved'])
                    ? $this->translator->translate('conversations.draft_saved') : null;
            }
        } catch (RegistrationRejected $failure) {
            $context['error'] = $this->translator->translate('diagnostics.' . $failure->diagnostics[0]);
            $status = $failure->diagnostics[0] === 'conversation_not_found' ? 404 : 422;
            $context['draft'] = is_int($draft) ? $draft : null;
        } catch (ConversationRefused $failure) {
            $context['error'] = $this->translator->translate(
                'diagnostics.' . $failure->diagnostic,
                $failure->parameters,
            );
            $missing = ['conversation_draft_not_found', 'conversation_folder_invalid'];
            $status = in_array($failure->diagnostic, $missing, true) ? 404 : 422;
            $context['draft'] = is_int($draft) && $failure->diagnostic !== 'conversation_draft_not_found'
                ? $draft : null;
        } catch (RegistrationThrottled $failure) {
            $context['error'] = $this->translator->translate(
                'diagnostics.account_wait',
                ['seconds' => $failure->seconds],
            );
            $status = 429;
            $context['draft'] = is_int($draft) ? $draft : null;
        } catch (Throwable) {
            $context['error'] = $this->translator->translate('conversations.unavailable');
            $status = 503;
        }

        try {
            if ($route === CoreRoutes::CONVERSATIONS) {
                $status = $this->listing($context, $actor, is_int($before) ? $before : null, $folder ?: null)
                    ?? $status;
            } elseif ($route === CoreRoutes::CONVERSATION && $id !== null) {
                $status = $this->conversation($context, $request, $actor, $id, is_int($before) ? $before : null)
                    ?? $status;
            }
        } catch (Throwable) {
            $context['error'] = $this->translator->translate('conversations.unavailable');
            $status = 503;
        }

        $template = $route === CoreRoutes::CONVERSATIONS_NEW ? 'new' : ($id === null ? 'index' : 'show');
        $action = $route === CoreRoutes::CONVERSATIONS_NEW ? $this->urls->generate(CoreRoutes::CONVERSATIONS_NEW)
            : $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id ?? 1]);
        $index = $this->urls->generate(CoreRoutes::CONVERSATIONS);
        $response = new Response($this->views->render('conversations/' . $template . '.twig', $context + [
            'title' => $this->translator->translate('conversations.title'),
            'action' => $action,
            'index_url' => $index,
            'drafts_url' => $index . '?view=drafts',
            'folders_url' => $index . '?view=folders',
            'new_url' => $this->urls->generate(CoreRoutes::CONVERSATIONS_NEW),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, self::headers());

        if ($status === 429) {
            $response->headers->set('Retry-After', '10');
        }

        return $response;
    }

    /**
     * Performs one CSRF-checked change and returns where to go next.
     *
     * @param Closure(string): string $field
     */
    private function write(string $route, int $actor, ?int $id, ?int $draft, Closure $field): string
    {
        $index = $this->urls->generate(CoreRoutes::CONVERSATIONS);
        $action = $field('action');
        $target = filter_var($field('folder'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($route === CoreRoutes::CONVERSATIONS) {
            $folders = $this->folders ?? throw new RegistrationRejected(['conversation_not_found']);
            $drafts = $this->drafts ?? throw new RegistrationRejected(['conversation_not_found']);

            if ($action === 'folder_create') {
                $folders->create($actor, $field('name'));

                return $index . '?view=folders';
            }

            if ($action === 'draft_delete' && $draft !== null) {
                $drafts->delete($actor, $draft);

                return $index . '?view=drafts';
            }

            if (!is_int($target)) {
                throw new ConversationRefused('conversation_folder_invalid');
            }

            match ($action) {
                'folder_rename' => $folders->rename($actor, $target, $field('name')),
                'folder_delete' => $folders->delete($actor, $target),
                default => throw new ConversationRefused('conversation_folder_invalid'),
            };

            return $index . '?view=folders';
        }

        if ($route === CoreRoutes::CONVERSATIONS_NEW) {
            if ($field('intent') === self::INTENT_DRAFT) {
                $drafts = $this->drafts ?? throw new RegistrationRejected(['conversation_not_found']);
                $saved = $drafts->save(
                    $actor,
                    $draft,
                    $field('recipient'),
                    $field('hidden_recipients'),
                    $field('subject'),
                    $field('content'),
                );

                return $this->urls->generate(CoreRoutes::CONVERSATIONS_NEW) . '?draft=' . $saved . '&saved=1';
            }

            $id = $this->conversations->start(
                $actor,
                $field('recipient'),
                $field('subject'),
                $field('content'),
                $field('hidden_recipients'),
            );

            if ($draft !== null && $this->drafts !== null) {
                $this->drafts->delete($actor, $draft);
            }

            return $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id]);
        }

        if ($id === null) {
            throw new RegistrationRejected(['conversation_not_found']);
        }

        if ($action === 'remove') {
            $this->conversations->remove($actor, $id);

            return $index;
        }

        if ($action === 'file' || $action === 'unfile') {
            $folders = $this->folders ?? throw new RegistrationRejected(['conversation_not_found']);

            if (!is_int($target)) {
                throw new ConversationRefused('conversation_folder_invalid');
            }

            $action === 'file' ? $folders->file($actor, $target, $id) : $folders->unfile($actor, $target, $id);

            return $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id]);
        }

        $this->conversations->reply($actor, $id, $field('content'));

        return $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id]);
    }

    /** @param array<string, mixed> $context */
    private function listing(array &$context, int $actor, ?int $before, ?int $folder): ?int
    {
        $index = $this->urls->generate(CoreRoutes::CONVERSATIONS);
        $context['folders'] = $this->folders?->all($actor) ?? [];

        if ($context['view'] === 'drafts') {
            foreach ($this->drafts?->all($actor) ?? [] as $row) {
                $context['drafts'][] = ['subject' => (string) $row['subject'], 'id' => (int) $row['id'],
                    'recipients' => trim($row['recipients'] . ', ' . $row['hidden_recipients'], ', '),
                    'url' => $this->urls->generate(CoreRoutes::CONVERSATIONS_NEW) . '?draft=' . $row['id'],
                    'date' => new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC'))];
            }

            return null;
        }

        if ($context['view'] === 'folders') {
            return null;
        }

        if ($folder !== null) {
            $name = $this->folders?->name($actor, $folder);

            if ($name === null) {
                $context['error'] = $this->translator->translate('diagnostics.conversation_folder_invalid');

                return 404;
            }

            $context['folder'] = ['id' => $folder, 'name' => $name];
        }

        $items = $this->conversations->inbox($actor, $before, $folder);
        $more = count($items) > 20;
        $items = array_slice($items, 0, 20);

        foreach ($items as $item) {
            $context['items'][] = ['subject' => $item['subject'], 'unread' => (bool) $item['unread'],
                'url' => $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $item['id']])];
        }

        if ($more) {
            $context['older'] = $index . '?' . ($folder === null ? '' : 'folder=' . $folder . '&')
                . 'before=' . $items[count($items) - 1]['id'];
        }

        return null;
    }

    /** @param array<string, mixed> $context */
    private function conversation(array &$context, Request $request, int $actor, int $id, ?int $before): ?int
    {
        $conversation = $this->conversations->show($actor, $id, $before);

        if ($conversation === null) {
            $context['error'] = $this->translator->translate('diagnostics.conversation_not_found');

            return 404;
        }

        $renderer = new BbcodeRenderer();
        $spoiler = $this->translator->translate('core.post.spoiler');

        foreach ($conversation['messages'] as &$message) {
            $message['date'] = new DateTimeImmutable($message['created_at'], new DateTimeZone('UTC'));
            // 2026-11-08: messages use the forum's BBCode (same sanitizing renderer as posts); plain text stays
            // plain (escaped, line breaks kept).
            $message['html'] = new SafeHtml(BbcodeRenderer::localize(
                $renderer->render((string) $message['content']),
                $spoiler,
            ));
        }

        unset($message);
        $context['folders'] = $this->folders?->forConversation($actor, $id) ?? [];
        $context['conversation'] = $conversation;

        if ($conversation['import_job_id'] !== null && $this->importedFiles !== null) {
            $fileBefore = $request->query->all()['files_before'] ?? null;
            $files = $this->importedFiles->listing($actor, $id, is_string($fileBefore) ? $fileBefore : null);
            $context['files'] = array_slice($files, 0, 50);
            $context['files_older'] = count($files) > 50
                ? $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id])
                    . '?files_before=' . $files[49]['id'] : null;
        }

        if ($conversation['older'] !== null) {
            $context['older'] = $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id])
                . '?before=' . $conversation['older'];
        }

        return null;
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html; charset=UTF-8',
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
