<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Http;

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
use Nolviko\Modules\Conversations\Infrastructure\PrivateConversations;
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

/** Authenticated participant-only SSR messaging; no user ID is accepted as the author. */
final class ConversationsHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly PrivateConversations $conversations,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ?PrivateImportFiles $importedFiles = null,
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
        $before = isset($query['before']) && is_string($query['before'])
            ? filter_var($query['before'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        $context = ['error' => null, 'subject' => '', 'content' => '', 'recipient' => '', 'conversation' => null,
            'items' => [], 'older' => null, 'preview' => null, 'preview_intent' => ComposerPreview::INTENT];
        $status = 200;

        // `?to=` from a profile's "Send message" fills in the recipient (2026-11-09).
        if (!$request->isMethod('POST') && is_string($query['to'] ?? null)) {
            $input['recipient'] = $query['to'];
        }

        foreach (['subject' => 160, 'recipient' => 32, 'content' => 8000] as $name => $limit) {
            $value = $field($name);
            $context[$name] = mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, $limit, 'UTF-8') : '';
        }

        try {
            if ($before === false) {
                throw new RegistrationRejected(['conversation_not_found']);
            }

            if ($request->isMethod('POST') && ComposerPreview::requested($input)) {
                // 2026-11-08: BBCode preview, nothing is sent.
                $context['preview'] = ComposerPreview::html($this->translator, $field('content'));
            } elseif ($request->isMethod('POST')) {
                if (!$this->csrf->isValid($field(CsrfTokens::FIELD))) {
                    return new Response(
                        $this->translator->translate('diagnostics.privacy_denied'),
                        403,
                        self::headers(),
                    );
                }

                if ($route === CoreRoutes::CONVERSATIONS_NEW) {
                    $id = $this->conversations->start(
                        $actor,
                        $field('recipient'),
                        $field('subject'),
                        $field('content'),
                    );
                } elseif ($id !== null) {
                    $this->conversations->reply($actor, $id, $field('content'));
                } else {
                    throw new RegistrationRejected(['conversation_not_found']);
                }

                return InstallerPage::redirect(
                    $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id]),
                );
            }
        } catch (RegistrationRejected $failure) {
            $context['error'] = $this->translator->translate('diagnostics.' . $failure->diagnostics[0]);
            $status = $failure->diagnostics[0] === 'conversation_not_found' ? 404 : 422;
        } catch (RegistrationThrottled $failure) {
            $context['error'] = $this->translator->translate(
                'diagnostics.account_wait',
                ['seconds' => $failure->seconds],
            );
            $status = 429;
        } catch (Throwable) {
            $context['error'] = $this->translator->translate('conversations.unavailable');
            $status = 503;
        }

        try {
            if ($route === CoreRoutes::CONVERSATIONS) {
                $items = $this->conversations->inbox($actor, is_int($before) ? $before : null);
                $more = count($items) > 20;
                $items = array_slice($items, 0, 20);

                foreach ($items as $item) {
                    $context['items'][] = ['subject' => $item['subject'], 'unread' => (bool) $item['unread'],
                        'url' => $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $item['id']])];
                }

                if ($more) {
                    $context['older'] = $this->urls->generate(CoreRoutes::CONVERSATIONS)
                        . '?before=' . $items[count($items) - 1]['id'];
                }
            } elseif ($route === CoreRoutes::CONVERSATION && $id !== null) {
                $conversation = $this->conversations->show($actor, $id, is_int($before) ? $before : null);

                if ($conversation === null) {
                    $context['error'] = $this->translator->translate('diagnostics.conversation_not_found');
                    $status = 404;
                } else {
                    $renderer = new BbcodeRenderer();
                    $spoiler = $this->translator->translate('core.post.spoiler');

                    foreach ($conversation['messages'] as &$message) {
                        $message['date'] = new DateTimeImmutable($message['created_at'], new DateTimeZone('UTC'));
                        // 2026-11-08: messages use the forum's BBCode (same sanitizing renderer as posts).
                        $message['html'] = new SafeHtml(BbcodeRenderer::localize(
                            $renderer->render((string) $message['content']),
                            $spoiler,
                        ));
                    }

                    unset($message);
                    $context['conversation'] = $conversation;
                    if ($conversation['import_job_id'] !== null && $this->importedFiles !== null) {
                        $fileBefore = $request->query->all()['files_before'] ?? null;
                        $files = $this->importedFiles->listing(
                            $actor,
                            $id,
                            is_string($fileBefore) ? $fileBefore : null
                        );
                        $context['files'] = array_slice($files, 0, 50);
                        $context['files_older'] = count($files) > 50
                            ? $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id])
                                . '?files_before=' . $files[49]['id'] : null;
                    }

                    if ($conversation['older'] !== null) {
                        $context['older'] = $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id])
                            . '?before=' . $conversation['older'];
                    }
                }
            }
        } catch (Throwable) {
            $context['error'] = $this->translator->translate('conversations.unavailable');
            $status = 503;
        }

        $template = $route === CoreRoutes::CONVERSATIONS_NEW ? 'new' : ($id === null ? 'index' : 'show');
        $action = $route === CoreRoutes::CONVERSATIONS_NEW ? $this->urls->generate(CoreRoutes::CONVERSATIONS_NEW)
            : $this->urls->generate(CoreRoutes::CONVERSATION, ['conversation' => $id ?? 1]);
        $response = new Response($this->views->render('conversations/' . $template . '.twig', $context + [
            'title' => $this->translator->translate('conversations.title'),
            'action' => $action,
            'index_url' => $this->urls->generate(CoreRoutes::CONVERSATIONS),
            'new_url' => $this->urls->generate(CoreRoutes::CONVERSATIONS_NEW),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, self::headers());

        if ($status === 429) {
            $response->headers->set('Retry-After', '10');
        }

        return $response;
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html; charset=UTF-8',
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
