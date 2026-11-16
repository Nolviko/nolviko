<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Nolviko\Core\Http\ThemeRequest;
use Nolviko\Core\Routing\ReleaseId;
use Nolviko\Core\Session\Session;
use Throwable;

/** A signed, expiring server-session preview is owner-bound and never updates the shared activation pointer. */
final readonly class ThemePresentation
{
    public function __construct(
        private ThemeBuildStore $builds,
        private Session $session,
        private ThemeRequest $request
    ) {
    }

    public function selection(): array
    {
        $build = $this->builds->active();
        $preview = false;
        $token = $this->session->get('_theme_preview');
        $secret = $this->session->get('_theme_preview_secret');
        try {
            if (
                is_array($token) && is_string($secret) && is_int($token['owner'] ?? null)
                && $token['owner'] === $this->session->get('_user_id') && is_int($token['expires'] ?? null)
                && $token['expires'] >= time() && is_string($token['build'] ?? null)
                && is_string($token['signature'] ?? null)
                && hash_equals(hash_hmac(
                    'sha256',
                    $token['owner'] . ':' . $token['build'] . ':' . $token['expires'],
                    $secret
                ), $token['signature'])
            ) {
                $candidate = $this->builds->get($token['build']);
                if ($candidate['owner'] === $token['owner'] && $this->builds->compatible($candidate)) {
                    $build = $candidate;
                    $preview = true;
                }
            }
        } catch (Throwable) {
            // A missing/expired/invalid private preview falls back to the last usable published build.
        }
        $release = ReleaseId::fromFile($this->builds->root . '/config/release.php');
        return ['theme' => $build['theme'] ?? 'default', 'themes' => isset($build['directory'])
                ? $build['directory'] . '/themes' : $this->builds->root . '/themes',
            'core' => isset($build['directory']) ? $build['directory'] . '/core' : $this->builds->root . '/views',
            'hash' => $build['hash'] ?? 'default-' . ($release ?? 'dev'),
            'cacheable' => $release !== null,
            'css' => ThemeTokens::css($build['tokens'] ?? []),
            'assets' => $build['assets'] ?? [], 'preview' => $preview, 'appearance' => $this->request->appearance()];
    }

    public function preview(int $owner, string $hash): void
    {
        $draft = $this->builds->draft($owner);
        if (
            $owner < 1 || $owner !== $this->session->get('_user_id') || $draft === null
            || !hash_equals($draft['build'], $hash)
        ) {
            throw new TemplateException('theme_preview_denied');
        }
        $build = $this->builds->get($hash);
        if ($build['owner'] !== $owner || !$this->builds->compatible($build)) {
            throw new TemplateException('theme_preview_denied');
        }
        $secret = bin2hex(random_bytes(32));
        $expires = time() + 900;
        $this->session->set('_theme_preview_secret', $secret);
        $this->session->set('_theme_preview', ['owner' => $owner, 'build' => $hash, 'expires' => $expires,
            'signature' => hash_hmac('sha256', $owner . ':' . $hash . ':' . $expires, $secret)]);
    }

    public function stop(): void
    {
        $this->session->remove('_theme_preview');
        $this->session->remove('_theme_preview_secret');
    }
}
