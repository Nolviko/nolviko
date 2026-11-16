<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Closure;
use Nolviko\Core\Cache\Cache;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\SeoMetadata;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * TemplateRenderer on Twig: the context is checked first (ViewContext); loader failures and TemplatePolicy
 * violations become TemplateException with a diagnostic ID.
 */
final class TwigRenderer implements TemplateRenderer
{
    private const NAME_DIAGNOSTICS = ['template_name_invalid:', 'template_outside_roots:'];

    /** Any form except an explicit GET form (the header search) may carry state or a token: never cache it. */
    private const UNCACHEABLE = '/<form\b(?![^>]*\bmethod="get")|_csrf_token/i';

    private const POLICY_DIAGNOSTICS = [TemplatePolicy::RAW_FORBIDDEN,
        TemplatePolicy::AUTOESCAPE_FORBIDDEN];

    public function __construct(
        private readonly Environment $twig,
        private readonly ?Cache $renderCache = null,
        private readonly ?Closure $renderIdentity = null,
        private readonly ?CspNonce $nonce = null,
        private readonly ?ThemeSlots $slots = null,
        private readonly ?SeoMetadata $seo = null,
    ) {
    }

    public function render(string $name, array $context = []): string
    {
        if ($name !== 'components/post.twig' && !str_starts_with($name, 'mail/')) {
            $context['seo'] = $this->seo?->view() ?? ['canonical'
                => is_string($context['canonical'] ?? null)
                ? $context['canonical'] : null, 'noindex' => true, 'origin' => null];
        }
        ViewContext::assert($context);

        try {
            return $this->cached($name, $context);
        } catch (LoaderError $failure) {
            throw new TemplateException(self::diagnostic($failure, $name), $failure);
        } catch (SyntaxError $failure) {
            if (in_array($failure->getRawMessage(), self::POLICY_DIAGNOSTICS, true)) {
                throw new TemplateException($failure->getRawMessage() . ':' . $name, $failure);
            }

            throw $failure;
        }
    }

    public function warm(array $names): void
    {
        foreach ($names as $name) {
            try {
                $this->twig->load($name);
            } catch (LoaderError $failure) {
                throw new TemplateException(self::diagnostic($failure, $name), $failure);
            } catch (SyntaxError $failure) {
                throw new TemplateException('theme_template_invalid:' . $name, $failure);
            }
        }
    }

    public function fragment(string $name, array $context): SafeHtml
    {
        if ($name !== 'components/post.twig' || array_keys($context) !== ['post']) {
            throw new TemplateException('theme_fragment_invalid');
        }
        return new SafeHtml($this->render($name, $context));
    }

    private function cached(string $name, array $context): string
    {
        $eligible = in_array($name, ['forums/index.twig', 'forums/show.twig', 'threads/show.twig',
            'components/post.twig'], true);
        if (
            !$eligible || $this->renderCache === null || $this->renderIdentity === null
            || $this->nonce === null || $this->slots?->hasProviders() || $this->seo?->filtered() === true
        ) {
            return $this->twig->render($name, $context);
        }
        $identity = ($this->renderIdentity)();
        $memberFragment = $name === 'components/post.twig';
        if (
            ($identity['preview'] ?? true) || ($identity['method'] ?? '') !== 'GET'
            || ($memberFragment ? ($identity['user'] ?? null) === null : ($identity['user'] ?? null) !== null)
        ) {
            return $this->twig->render($name, $context);
        }
        // Current handlers have already authorized and fetched this exact view context before this lookup.
        $serialized = serialize([$identity, $name, $context]);
        if (
            strlen($serialized) > 2097152 || preg_match(
                '/csrf|password|secret|token/i',
                $serialized
            ) === 1
        ) {
            return $this->twig->render($name, $context);
        }
        $key = 'render.' . hash('sha256', $serialized);
        $nonce = htmlspecialchars($this->nonce->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $marker = '__NOLVIKO_CACHED_NONCE__';
        $value = $this->renderCache->get($key);
        if (is_string($value)) {
            return self::nonceAttributes($value, $marker, $nonce);
        }
        $html = $this->twig->render($name, $context);
        if (strlen($html) <= 2097152 && preg_match(self::UNCACHEABLE, $html) !== 1) {
            $stored = self::nonceAttributes($html, $nonce, $marker);
            if ($nonce !== '' && !str_contains($stored, $nonce)) {
                $this->renderCache->set($key, $stored, 60);
            }
        }
        return $html;
    }

    private static function nonceAttributes(string $html, string $from, string $to): string
    {
        $pattern = '~(<(?:script|style)\\b[^>]*\\bnonce=")' . preg_quote($from, '~') . '(")~i';
        return preg_replace_callback(
            $pattern,
            static fn(array $match): string => $match[1] . $to . $match[2],
            $html
        ) ?? $html;
    }

    private static function diagnostic(LoaderError $failure, string $name): string
    {
        foreach (self::NAME_DIAGNOSTICS as $prefix) {
            $position = strpos($failure->getRawMessage(), $prefix);

            if ($position !== false) {
                return substr($failure->getRawMessage(), $position);
            }
        }

        return 'template_missing:' . $name;
    }
}
