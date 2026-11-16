<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use InvalidArgumentException;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\GroupAdministration;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionType;
use Nolviko\Modules\Permissions\Domain\Precedence;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/admin/groups/{group}` (M04.14): rename a custom group, its members (add by username, remove) and its rules,
 * site-wide or for one forum (`?forum=`). The rule form lists every catalog key; only changed values are saved.
 */
final readonly class GroupHandler implements RouteHandler
{
    private const ID = '/^[1-9][0-9]{0,17}$/D';

    public function __construct(
        private GroupAdministration $groups,
        private PermissionCatalog $catalog,
        private ForumRepository $forums,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $id = (int) ($parameters['group'] ?? 0);
        $group = $this->groups->find($id);

        if ($group === null) {
            return $this->errors->notFound();
        }

        $subject = $this->subjects->of($request);
        $post = $request->request->all();
        $query = $request->query->all();
        $text = static fn(array $input, string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $forum = preg_match(self::ID, $text($request->isMethod('POST') ? $post : $query, 'forum')) === 1
            ? (int) $text($request->isMethod('POST') ? $post : $query, 'forum') : null;
        $after = preg_match(self::ID, $text($query, 'after')) === 1 ? (int) $text($query, 'after') : 0;
        $self = $this->urls->generate(CoreRoutes::ADMIN_GROUP, ['group' => (string) $group->id]);
        $flash = '_group_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        $error = null;
        $status = 200;

        if ($forum !== null && $this->forums->find($forum) === null) {
            return $this->errors->notFound();
        }

        try {
            if ($request->isMethod('POST')) {
                if (!$this->csrf->isValid($text($post, CsrfTokens::FIELD))) {
                    throw new PermissionError('group_admin_denied');
                }

                $fresh = $this->admin->isFresh();
                $message = 'admin.groups.saved';

                switch ($text($post, 'action')) {
                    case 'rename':
                        $this->groups->rename($subject, $fresh, $group->id, $text($post, 'name'));
                        break;
                    case 'add_member':
                        $this->groups->addMember($subject, $fresh, $group->id, $text($post, 'member'));
                        break;
                    case 'remove_member':
                        $user = preg_match(self::ID, $text($post, 'user')) === 1 ? (int) $text($post, 'user') : 0;
                        $this->groups->removeMember($subject, $fresh, $group->id, $user);
                        break;
                    case 'save_rules':
                        $rules = is_array($post['rule'] ?? null) ? array_filter(
                            $post['rule'],
                            static fn(mixed $value, mixed $key): bool => is_string($value) && is_string($key),
                            ARRAY_FILTER_USE_BOTH,
                        ) : [];
                        $changed = $this->groups->saveRules($subject, $fresh, $group->id, $forum, $rules);
                        $message = $changed === 0 ? 'admin.groups.rules_unchanged' : 'admin.groups.rules_saved';
                        break;
                    default:
                        throw new InvalidArgumentException('group_action_invalid');
                }

                $this->session->set($flash, $this->translator->translate($message));

                return InstallerPage::redirect($self . ($forum === null ? '' : '?forum=' . $forum)
                    . ($text($post, 'action') === 'save_rules' ? '#rules' : ''));
            }
        } catch (PermissionError) {
            [$status, $error] = [403, $this->translator->translate('admin.groups.denied')];
        } catch (GroupRuleViolation $violation) {
            [$status, $error] = [422, $this->errorText($violation->diagnostic)];
        } catch (InvalidArgumentException $failure) {
            [$status, $error] = [422, $this->errorText($failure->getMessage())];
        } catch (Throwable) {
            [$status, $error] = [503, $this->translator->translate('admin.groups.unavailable')];
        }

        $group = $this->groups->find($group->id) ?? $group;
        $members = $group->id === BuiltInGroup::Guests->value ? [] : $this->groups->members($group->id, $after);
        $older = count($members) > GroupAdministration::MEMBERS_PAGE;
        $members = array_slice($members, 0, GroupAdministration::MEMBERS_PAGE);
        $current = $this->groups->rules($group->id, $forum);
        $forumOptions = [];

        foreach ($this->forums->all() as $item) {
            $forumOptions[] = ['id' => $item->id, 'label' => str_repeat("\u{2003}", $item->depth) . $item->title,
                'selected' => $item->id === $forum];
        }

        return new Response($this->views->render('admin/group.twig', [
            'title' => $this->translator->translate('admin.groups.group_title', ['name' => $group->name]),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_GROUPS),
            'group' => ['id' => $group->id, 'name' => $group->name, 'slug' => $group->slug,
                'built_in' => $group->builtIn, 'guests' => $group->id === BuiltInGroup::Guests->value],
            'action' => $self, 'list_url' => $this->urls->generate(CoreRoutes::ADMIN_GROUPS),
            'members' => $members,
            'older_url' => $older ? $self . '?after=' . $members[count($members) - 1]['id'] : null,
            'forum' => $forum, 'forums' => $forumOptions,
            'rules' => array_map(
                fn(PermissionDefinition $definition): array => $this->row($definition, $group->id, $current),
                array_values($this->catalog->all()),
            ),
            'error' => $error, 'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(), 'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * One editor row: translated label, current form value and the allowed choices.
     *
     * @param array<string, string> $current
     * @return array<string, mixed>
     */
    private function row(PermissionDefinition $definition, int $groupId, array $current): array
    {
        $key = $definition->key;
        $inherit = ['value' => GroupAdministration::INHERIT,
            'label' => $this->translator->translate('admin.groups.inherit')];
        $choices = match ($definition->type) {
            PermissionType::Bool => [$inherit,
                ['value' => GroupAdministration::ALLOW, 'label' => $this->translator->translate('admin.groups.allow')],
                ['value' => GroupAdministration::DENY, 'label' => $this->translator->translate('admin.groups.deny')],
                ['value' => Precedence::NEVER, 'label' => $this->translator->translate('admin.groups.never')]],
            PermissionType::Enum => [$inherit, ...array_map(
                fn(string $option): array => ['value' => $option, 'label' => $this->label('option', $option)],
                $definition->options,
            )],
            PermissionType::Int => [],
        };

        return ['key' => $key, 'field' => 'rule-' . str_replace('.', '-', $key),
            'label' => $this->label('perm', $key),
            'type' => $definition->type->value, 'value' => $current[$key] ?? GroupAdministration::INHERIT,
            'choices' => $choices, 'min' => $definition->min, 'max' => min($definition->max, 999999999),
            'locked' => GroupAdministration::locked($groupId, $key),
            'section' => $this->label('section', explode('.', $key)[0])];
    }

    /** Diagnostic ID => translated text (IDs are the `admin.groups.error.*` keys). */
    private function errorText(string $diagnostic): string
    {
        $key = 'admin.groups.error.' . $diagnostic;

        return $this->translator->has($key) ? $this->translator->translate($key)
            : $this->translator->translate('admin.groups.unavailable');
    }

    /** `admin.groups.<kind>.<name>`, or the raw name for a key a later catalog adds without a translation. */
    private function label(string $kind, string $name): string
    {
        $key = 'admin.groups.' . $kind . '.' . $name;

        return $this->translator->has($key) ? $this->translator->translate($key) : $name;
    }
}
