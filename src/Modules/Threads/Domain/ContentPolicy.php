<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Config\PostingRules;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Users\Domain\UserRepository;

/**
 * Content anti-spam (M03.15a) for new threads, replies and edits: blocked words, blocked link domains and a link
 * limit for new users. Links are counted in the rendered HTML (every `href` and `src` the BBCode renderer wrote,
 * bare URLs included), so what is counted is exactly what readers would get; the renderer already marks user
 * links `rel="nofollow ugc"`. Moderators of the forum (`moderation.post.hide`) are exempt.
 */
final class ContentPolicy
{
    private const LINK_PATTERN = '/\s(?:href|src)="([^"]*)"/';

    /** Images in the rendered post ([img], including uploaded ones). */
    private const IMAGE_PATTERN = '/<img\b[^>]*\bclass="bb-img"/';

    /** The site's own gallery images: not links for the new-member limit (2026-11-14). */
    private const OWN_IMAGE_PATH = '#^/gallery/images/[1-9][0-9]{0,17}/(?:full|thumb)$#D';

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        public readonly PostingRules $rules,
        private readonly PostRepository $posts,
        private readonly UserRepository $users,
        ?Closure $clock = null,
        private readonly ?string $siteHost = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * @param string $text Everything the user typed (title and message), for the word list.
     * @param string $renderedHtml The BBCode renderer's output of the message.
     *
     * @throws ThreadRuleViolation `post_content_blocked`, `post_link_blocked` or `post_links_limited` (`max`).
     */
    public function check(SubjectPermissions $subject, int $forumId, string $text, string $renderedHtml): void
    {
        if ($subject->allows(ModerateContent::MODERATE, $forumId)) {
            return;
        }

        foreach ($this->rules->blockedWords as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
                throw new ThreadRuleViolation('post_content_blocked');
            }
        }

        $images = preg_match_all(self::IMAGE_PATTERN, $renderedHtml);

        if ($images > $this->rules->maxImages) {
            throw new ThreadRuleViolation('post_images_limited', ['max' => $this->rules->maxImages]);
        }

        preg_match_all(self::LINK_PATTERN, $renderedHtml, $matches);
        $links = array_values(array_filter(
            $matches[1],
            fn(string $link): bool => !$this->ownImage(html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ));

        foreach ($links as $link) {
            if ($this->blockedHost(html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                throw new ThreadRuleViolation('post_link_blocked');
            }
        }

        $tooMany = count($links) > $this->rules->newUserMaxLinks;

        if ($tooMany && $subject->userId !== null && $this->isNew($subject->userId)) {
            throw new ThreadRuleViolation('post_links_limited', ['max' => $this->rules->newUserMaxLinks]);
        }
    }

    private function blockedHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        foreach ($this->rules->blockedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fewer visible posts than `linkMinPosts`, or an account younger than `linkMinAgeHours`.
     */
    /** An image of this site's gallery (added with the editor's image button): not a link to elsewhere. */
    private function ownImage(string $url): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        return is_array($parts) && ($host === '' || ($this->siteHost !== null && $host === strtolower($this->siteHost)))
            && preg_match(self::OWN_IMAGE_PATH, (string) ($parts['path'] ?? '')) === 1
            && !isset($parts['query']) && !isset($parts['fragment']);
    }

    private function isNew(int $userId): bool
    {
        $user = $this->users->findById($userId);
        $ageSeconds = $user === null ? 0 : ($this->clock)()->getTimestamp() - $user->createdAt->getTimestamp();

        return $ageSeconds < $this->rules->linkMinAgeHours * 3600
            || $this->posts->visiblePostCount($userId, $this->rules->linkMinPosts) < $this->rules->linkMinPosts;
    }
}
