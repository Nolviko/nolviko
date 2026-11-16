<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/admin/taxonomy` (2026-11-08): thread prefixes (per forum or board-wide, one of the fixed colours) and tags.
 * Every change needs a fresh administrator session and is audited by ThreadTaxonomy.
 */
final readonly class TaxonomyAdminHandler implements RouteHandler
{
    public function __construct(
        private ThreadTaxonomy $taxonomy,
        private ForumRepository $forums,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $flash = '_taxonomy_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        $error = null;
        $status = 200;

        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
            $id = static fn(string $key): ?int => ctype_digit($text($key)) && $text($key) !== '0' ? (int) $text($key)
                : null;

            if (
                !$this->csrf->isValid($text(CsrfTokens::FIELD)) || !$this->admin->isFresh()
                || $subject->userId === null || !$subject->allows('admin.access')
            ) {
                [$status, $error] = [403, $this->translator->translate('admin.site.denied')];
            } else {
                try {
                    match ($text('action')) {
                        'create_tag' => $this->taxonomy->createTag($text('name'), $subject->userId),
                        'create_prefix' => $this->taxonomy->createPrefix(
                            $text('label'),
                            $text('color'),
                            $id('forum'),
                            $subject->userId,
                        ),
                        'delete_tag' => $this->taxonomy->delete('tag', (int) $id('id'), $subject->userId),
                        'delete_prefix' => $this->taxonomy->delete('prefix', (int) $id('id'), $subject->userId),
                        default => throw new ThreadRuleViolation('moderation_action_invalid'),
                    };
                    $this->session->set($flash, $this->translator->translate('admin.taxonomy.saved'));

                    return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_TAXONOMY));
                } catch (ThreadRuleViolation $violation) {
                    [$status, $error] = [422, $this->translator->translate(
                        'diagnostics.' . $violation->diagnostic,
                        $violation->parameters,
                    )];
                }
            }
        }

        $forums = [];

        foreach ($this->forums->all() as $forum) {
            if ($forum->type === ForumType::Forum) {
                $forums[$forum->id] = $forum->title;
            }
        }

        return new Response($this->views->render('admin/taxonomy.twig', [
            'title' => $this->translator->translate('admin.taxonomy.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_TAXONOMY),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_TAXONOMY),
            'tags' => $this->taxonomy->tags(),
            'prefixes' => array_map(static fn(array $prefix): array => $prefix + [
                'forum' => $prefix['forum_id'] === null ? null : ($forums[$prefix['forum_id']] ?? null),
            ], $this->taxonomy->prefixes(all: true)),
            'forums' => array_map(
                static fn(int $id, string $title): array => ['id' => $id, 'title' => $title],
                array_keys($forums),
                array_values($forums)
            ),
            'colors' => ThreadTaxonomy::COLORS,
            'max_label' => ThreadTaxonomy::MAX_LABEL,
            'error' => $error,
            'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
