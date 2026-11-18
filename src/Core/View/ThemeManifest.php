<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Nolviko\Core\Updates\PackageUpdates;

/** Version-one, bounded theme metadata; a package can never choose a filesystem path as its identity. */
final readonly class ThemeManifest
{
    public const ENGINE = 1;

    private const KEYS = ['id', 'parent', 'version', 'engine', 'minimum_php', 'assets', 'slots', 'upstream',
        'repository'];

    public function __construct(public array $data)
    {
    }

    public static function parse(string $json, string $id): self
    {
        $data = strlen($json) <= 65536 ? json_decode($json, true, 16) : null;
        if (
            !is_array($data) || array_is_list($data) || array_diff(array_keys($data), self::KEYS) !== []
            || preg_match(ThemeChain::ID_PATTERN, $id) !== 1 || ($data['id'] ?? null) !== $id
        ) {
            throw new TemplateException('theme_manifest_invalid:' . $id);
        }
        $data += ['parent' => null, 'version' => '1.0.0', 'engine' => self::ENGINE,
            'minimum_php' => '8.2.35', 'assets' => [], 'slots' => [], 'upstream' => []];
        if (
            ($data['parent'] !== null && (!is_string($data['parent'])
                || preg_match(ThemeChain::ID_PATTERN, $data['parent']) !== 1))
            || !is_string($data['version']) || preg_match(
                '/^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}$/D',
                $data['version']
            ) !== 1
            || $data['engine'] !== self::ENGINE || !is_string($data['minimum_php'])
            // Optional GitHub repository for update checks in the panel (2026-11-17).
            || (isset($data['repository']) && (!is_string($data['repository'])
                || preg_match(PackageUpdates::REPOSITORY_PATTERN, $data['repository']) !== 1))
            // PHP 8.2-8.5 (beta.10, owner plan 2026-11-18).
            || preg_match('/^8\.[2-5]\.[0-9]{1,3}$/D', $data['minimum_php']) !== 1
        ) {
            throw new TemplateException('theme_manifest_invalid:' . $id);
        }
        foreach (['assets', 'slots', 'upstream'] as $key) {
            if (!is_array($data[$key]) || count($data[$key]) > 100) {
                throw new TemplateException('theme_manifest_invalid:' . $id);
            }
        }
        if (
            !array_is_list($data['assets']) || count(array_unique(
                $data['assets'],
                SORT_REGULAR
            )) !== count($data['assets'])
        ) {
            throw new TemplateException('theme_manifest_invalid:' . $id);
        }
        foreach ($data['assets'] as $name) {
            if (
                !is_string($name) || preg_match('/^[a-z][a-z0-9.-]{0,79}\.(css|js)$/D', $name) !== 1
                || str_contains($name, '..')
            ) {
                throw new TemplateException('theme_asset_invalid');
            }
        }
        foreach ($data['slots'] as $slot => $version) {
            if (!is_string($slot) || !is_int($version) || ThemeSlots::version($slot) !== $version) {
                throw new TemplateException('theme_slot_incompatible');
            }
        }
        foreach ($data['upstream'] as $name => $hash) {
            if (
                !is_string($name) || !self::templateName($name) || !is_string($hash)
                || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            ) {
                throw new TemplateException('theme_upstream_invalid');
            }
        }
        return new self($data);
    }

    public static function templateName(string $name): bool
    {
        return preg_match('~^[a-z0-9_][a-z0-9_.-]*(/[a-z0-9_][a-z0-9_.-]*)*\.twig$~D', $name) === 1
            && !str_contains($name, '..');
    }
}
