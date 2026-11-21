<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Community\Infrastructure\CommunityActions;
use Nolviko\Modules\Community\Infrastructure\CommunityActivity;
use Nolviko\Modules\Community\Infrastructure\CommunityInteractions;
use Nolviko\Modules\Community\Infrastructure\CommunityNotifications;
use Nolviko\Modules\Community\Infrastructure\CommunityRead;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use Nolviko\Modules\Community\Infrastructure\MemberStanding;
use Nolviko\Modules\Community\Infrastructure\PrivateMedia;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Posts\Domain\PostPreview;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Community pages share HTTPS, kernel CSRF and subject-bound commands. */
final class CommunityHandler implements RouteHandler
{
    /** Profile post totals are counted up to here and shown as "10,000+" beyond (bounded index walk). */
    private const POST_TOTAL_CAP = 10000;

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly MemberProfiles $profiles,
        private readonly CommunityRead $read,
        private readonly CommunityInteractions $interactions,
        private readonly CommunityNotifications $notifications,
        private readonly CommunityActions $actions,
        private readonly PrivateMedia $media,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly CommunityActivity $activity,
        private readonly ?UserRepository $users = null,
        private readonly ?PostRepository $posts = null,
        private readonly ?ForumVisibility $visibility = null,
        private readonly ?MemberStanding $standing = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $section = $parameters['section'] ?? 'members';
        $query = $request->query->all();
        $status = 200;
        $error = null;
        $actor = $subject->userId;
        $user = CommunityActions::id($query['user'] ?? null);
        $thread = CommunityActions::id($query['thread'] ?? null);
        $before = CommunityActions::id($query['before'] ?? null);
        $after = CommunityActions::id($query['after'] ?? null);

        try {
            if (isset($parameters['media'])) {
                return $this->media->download($subject, $parameters['media'], ($query['thumb'] ?? '') === '1');
            }

            if ($request->isMethod('POST')) {
                $file = $request->files->get('file');
                $posted = $request->request->all();

                // Moderators name the member (2026-11-07) instead of typing a member number.
                if (($posted['action'] ?? '') === 'reset_avatar' && is_string($posted['target_name'] ?? null)) {
                    $named = $this->users?->findByUsername(trim($posted['target_name']));
                    $posted['target'] = $named === null ? '' : (string) $named->id;

                    if ($named === null) {
                        throw new RegistrationRejected(['community_member_unknown']);
                    }
                }

                $this->actions->run($subject, $posted, $file instanceof UploadedFile ? $file : null);

                if ($section === 'profile' && ($posted['action'] ?? '') === 'block') {
                    $section = 'settings';
                }

                // Inline thread-page forms (2026-11-07) come back to the post or thread they acted on. Only IDs are
                // accepted and URLs are generated here, so this can never become an open redirect.
                $returnPost = CommunityActions::id($posted['return_post'] ?? null);
                $returnThread = CommunityActions::id($posted['return_thread'] ?? null);

                if ($returnPost > 0 || $returnThread > 0) {
                    return new Response('', 303, ['Location' => $returnPost > 0
                        ? $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $returnPost])
                        : $this->urls->generate(CoreRoutes::THREAD_ID, ['thread' => (string) $returnThread]),
                        'Cache-Control' => 'private, no-store']);
                }

                return new Response('', 303, ['Location' => $this->urls->generate(
                    CoreRoutes::COMMUNITY,
                    ['section' => $section]
                ) . '?' . http_build_query(['user' => $user, 'thread' => $thread,
                        'before' => $before, 'after' => $after]), 'Cache-Control' => 'private, no-store']);
            }
        } catch (RegistrationRejected $failure) {
            if (in_array($failure->diagnostics[0], ['community_not_found', 'community_denied'], true)) {
                return $this->errors->notFound();
            }

            // `id:field` diagnostics (a required profile field, roadmap 21) name the field in the message.
            [$id, $field] = explode(':', $failure->diagnostics[0], 2) + [1 => ''];
            $error = $this->translator->translate('diagnostics.' . $id, ['field' => $field]);
            $status = 422;
        } catch (RegistrationThrottled $failure) {
            $error = $this->translator->translate('diagnostics.account_wait', ['seconds' => $failure->seconds]);
            $status = 429;
        }

        $context = ['profile' => null, 'items' => [], 'data' => null, 'avatar' => null, 'older' => null,
            'joined_at' => null, 'post_total' => null, 'post_total_capped' => false, 'recent_posts' => [],
            'profile_fields' => [], 'standing' => null, 'badges' => [], 'threads_total' => null, 'last_seen' => null,
            // Profile actions (2026-11-09).
            'settings_url' => $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => 'settings']),
            'gallery_url' => $user !== null ? $this->urls->generate(CoreRoutes::GALLERY) . '?user=' . $user : null,
            'message_url' => $actor !== null && $user !== null && $user !== $actor
                ? $this->urls->generate(CoreRoutes::CONVERSATIONS_NEW) : null,
            'guest_requests' => $this->activity->guestRequests(),
            'may_moderate' => $subject->allows('moderation.profile.manage'),
            'blocks' => $section === 'settings' && $actor !== null ? $this->profiles->blocks($actor) : [],
            'section' => $section, 'actor' => $actor, 'error' => $error, 'user_id' => $user, 'thread_id' => $thread];

        try {
            if (in_array($section, ['settings', 'notifications', 'bookmarks'], true) && $actor === null) {
                return $this->errors->notFound();
            }

            if ($section === 'settings' || $section === 'profile') {
                $target = $section === 'settings' ? (int) $actor : $user;
                $context['profile'] = $this->profiles->find($subject, $target);

                if ($context['profile'] === null) {
                    return $this->errors->notFound();
                }

                $context['avatar'] = $this->read->avatar($target);

                $context['joined_at'] = is_string($context['profile']['joined_at'] ?? null)
                    ? new DateTimeImmutable($context['profile']['joined_at'], new DateTimeZone('UTC')) : null;

                // Profile details (2026-11-07): a capped total and the newest posts the reader may see.
                if ($section === 'profile' && $this->posts !== null && $this->visibility !== null) {
                    $context['post_total'] = $this->posts->visiblePostCount($target, self::POST_TOTAL_CAP);
                    $context['post_total_capped'] = $context['post_total'] >= self::POST_TOTAL_CAP;
                    $context['recent_posts'] = array_map(
                        fn(PostPreview $preview): array => ['title' => $preview->threadTitle,
                            'excerpt' => $preview->excerpt, 'date' => $preview->createdAt,
                            'url' => $this->urls->generate(
                                CoreRoutes::POST_SHOW,
                                ['post' => (string) $preview->postId],
                            )],
                        $this->posts->latestByUser($this->visibility->readable($subject), $target, 5),
                    );
                }
                $context['may_reset_avatar'] = $actor === $target || $subject->allows('moderation.profile.manage');

                // Standing and custom fields (2026-11-08).
                if ($this->standing !== null) {
                    $values = $this->standing->values($target);
                    // A choice field stores an option ID and shows its label (roadmap 22).
                    $context['profile_fields'] = array_map(
                        static fn(array $field): array => $field + ['value' => $values[$field['id']] ?? '',
                            'display' => MemberStanding::display($field, $values[$field['id']] ?? '')],
                        $this->standing->fields(),
                    );
                    // Required fields still empty (roadmap 21) are asked here, never at sign-in.
                    $context['missing_fields'] = $section === 'settings' && $actor !== null
                        ? $this->standing->missingRequired($actor) : [];

                    if ($section === 'profile') {
                        $standing = $this->standing->standing([$target])[$target]
                            ?? ['posts' => 0, 'reputation' => 0, 'rank' => null];
                        $context['standing'] = $standing;
                        $context['badges'] = MemberStanding::badges($standing, $context['joined_at']);
                        $facts = $this->standing->profileFacts(
                            $target,
                            $this->visibility?->readable($subject)->forumIds ?? [],
                        );
                        $context['threads_total'] = $facts['threads'];
                        $context['last_seen'] = $facts['last_seen'] === null ? null
                            : new DateTimeImmutable($facts['last_seen'], new DateTimeZone('UTC'));
                    }
                }
            } elseif ($section === 'members' || $section === 'online') {
                $rows = $this->profiles->directory($subject, $after, $section === 'online');
                $context['items'] = array_map(
                    static fn(array $row): array => $row + ['joined' => is_string($row['joined_at'] ?? null)
                        ? new DateTimeImmutable($row['joined_at'], new DateTimeZone('UTC')) : null],
                    array_slice($rows, 0, 25),
                );

                if (count($rows) > 25) {
                    $context['older'] = $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => $section])
                        . '?after=' . $context['items'][24]['id'];
                }
            } elseif ($section === 'notifications' || $section === 'bookmarks') {
                $rows = $section === 'notifications' ? $this->notifications->page($subject, $before)
                    : $this->interactions->bookmarks($subject, $before);
                $context['items'] = array_slice($rows, 0, 25);

                if (count($rows) > 25) {
                    $context['older'] = $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => $section])
                        . '?before=' . $context['items'][24]['id'];
                }
            } elseif ($section === 'attachments') {
                $postId = CommunityActions::id($query['post'] ?? null);
                $fileBefore = $query['files_before'] ?? null;
                $rows = $this->read->attachments($subject, $postId, is_string($fileBefore) ? $fileBefore : null);
                $context['items'] = array_slice($rows, 0, 50);
                if (count($rows) > 50) {
                    $context['older'] = $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => 'attachments'])
                        . '?post=' . $postId . '&files_before=' . $rows[49]['id'];
                }
            } elseif ($section === 'thread') {
                $context['data'] = $this->read->thread($subject, $thread, $before);

                if ($context['data']['more']) {
                    $context['older'] = $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => 'thread'])
                        . '?thread=' . $thread . '&before=' . $context['data']['cursor'];
                }
            }

            $this->profiles->touch($subject);
        } catch (RegistrationRejected $failure) {
            return $this->errors->notFound();
        }

        $response = new Response($this->views->render('community/page.twig', $context + [
            'title' => $this->translator->translate('community.' . $section),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);

        if ($status === 429) {
            $response->headers->set('Retry-After', '2');
        }

        return $response;
    }
}
