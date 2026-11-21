<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Closure;
use Nolviko\Core\Http\CspNonce;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/** Only validated CSS and installed trusted assets become nonce-carrying HTML; tokens never enter script code. */
final class ThemeViewExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ThemeSlots $slots,
        private readonly array $selection,
        private readonly ?CspNonce $nonce,
        private readonly string $appearanceUrl,
        private readonly Closure $fragment,
        private readonly string $forumUrl,
        private readonly ?Closure $viewer = null,
        private readonly ?Closure $site = null,
        private readonly ?Closure $adminNotices = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('theme_slot', $this->slots->render(...)),
            new TwigFunction('theme_fragment', $this->fragment),
            // beta.17: notices for the top of admin pages, computed only when an admin layout asks for them.
            new TwigFunction('admin_notices', fn(): array => $this->adminNotices === null ? []
                : ($this->adminNotices)())];
    }

    public function getGlobals(): array
    {
        $nonce = htmlspecialchars($this->nonce?->value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $css = '<style nonce="' . $nonce . '">' . $this->selection['css'] . '</style>';
        $scripts = '';
        foreach ($this->selection['assets'] as $name => $body) {
            if (str_ends_with($name, '.css')) {
                $css .= '<style nonce="' . $nonce . '">' . $body . '</style>';
            } else {
                $scripts .= '<script nonce="' . $nonce . '">' . $body . '</script>';
            }
        }
        return ['forum_home_url' => $this->forumUrl,
            'theme_mode' => $this->selection['appearance'],
            'theme_build' => $this->selection['hash'],
            'theme_preview' => $this->selection['preview'], 'theme_styles' => new SafeHtml($css),
            'theme_scripts' => new SafeHtml($scripts), 'appearance_url' => $this->appearanceUrl,
            'viewer' => $this->viewer(), 'site' => $this->site()];
    }

    /**
     * Site name and description from the admin settings (ADR-034); empty strings when unset or unreadable, so
     * templates fall back to the product wordmark.
     *
     * @return array{name: string, description: string}
     */
    private function site(): array
    {
        $site = ['name' => '', 'description' => '', 'announcement' => '', 'gallery' => 'off', 'post_images' => 'off',
            'images_per_post' => ''];

        if ($this->site === null) {
            return $site;
        }

        try {
            $state = ($this->site)();
        } catch (Throwable) {
            return $site;
        }

        foreach (array_keys($site) as $key) {
            $site[$key] = is_array($state) && is_string($state[$key] ?? null) ? $state[$key] : '';
        }

        return $site;
    }

    /** Header state only (signed in, may administer, may moderate); never names or other personal data. */
    private function viewer(): array
    {
        $viewer = ['signed_in' => false, 'admin' => false, 'moderator' => false];
        if ($this->viewer === null) {
            return $viewer + ['notifications' => 0, 'messages' => 0];
        }
        try {
            $state = ($this->viewer)();
        } catch (Throwable) {
            return $viewer + ['notifications' => 0, 'messages' => 0];
        }
        foreach (array_keys($viewer) as $key) {
            $viewer[$key] = is_array($state) && ($state[$key] ?? false) === true;
        }
        foreach (['notifications', 'messages'] as $key) {
            $count = is_array($state) ? ($state[$key] ?? 0) : 0;
            $viewer[$key] = $viewer['signed_in'] && is_int($count) ? max(0, $count) : 0;
        }
        return $viewer;
    }
}
