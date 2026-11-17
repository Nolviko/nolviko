<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use JsonException;
use Nolviko\Core\Updates\PackageUpdates;

/** Strict data-only v1 manifest; discovery never evaluates PHP or arbitrary loader paths. */
final readonly class ExtensionManifest
{
    public const ID_PATTERN = '~^[a-z][a-z0-9_]{0,31}/[a-z][a-z0-9_]{0,31}$~D';

    /** @param array<string, mixed> $data */
    private function __construct(
        public string $id,
        public string $version,
        public array $data,
    ) {
    }

    public static function parse(string $json): self
    {
        if (strlen($json) > 65536) {
            throw new ExtensionFailure('extension_manifest_invalid');
        }

        try {
            $data = json_decode($json, true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new ExtensionFailure('extension_manifest_invalid', 0, $failure);
        }

        $allowed = ['id', 'name', 'version', 'api', 'engine', 'php', 'license', 'depends_on', 'conflicts',
            'providers', 'routes', 'permissions', 'migrations', 'events', 'subscribes', 'ui_slots',
            'translations', 'packages', 'repository'];
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $allowed) !== []) {
            throw new ExtensionFailure('extension_manifest_invalid');
        }

        // Optional GitHub repository for update checks in the panel ("owner/name", 2026-11-17, API 1.2).
        $repository = $data['repository'] ?? null;
        if (
            $repository !== null
            && (!is_string($repository) || preg_match(PackageUpdates::REPOSITORY_PATTERN, $repository) !== 1)
        ) {
            throw new ExtensionFailure('extension_manifest_invalid');
        }

        foreach (['id', 'name', 'version', 'engine', 'php', 'license'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '' || strlen($data[$key]) > 256) {
                throw new ExtensionFailure('extension_manifest_invalid');
            }
        }

        if (
            preg_match(self::ID_PATTERN, $data['id']) !== 1 || ($data['api'] ?? null) !== 1
            || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $data['version']) !== 1
        ) {
            throw new ExtensionFailure('extension_manifest_invalid');
        }

        foreach (['depends_on', 'conflicts', 'packages'] as $key) {
            $map = $data[$key] ?? [];
            if (!is_array($map) || count($map) > 64) {
                throw new ExtensionFailure('extension_manifest_invalid');
            }

            foreach ($map as $id => $constraint) {
                if (
                    !is_string($id) || preg_match(
                        $key === 'packages' ? '~^[a-z][a-z0-9_.-]{0,63}/[a-z][a-z0-9_.-]{0,63}$~D' : self::ID_PATTERN,
                        $id,
                    ) !== 1 || !is_string($constraint)
                ) {
                    throw new ExtensionFailure('extension_manifest_invalid');
                }

                (new ComposerVersionConstraint())->accepts('1.0.0', $constraint);
            }
        }

        $lists = ['providers', 'routes', 'permissions', 'migrations', 'events', 'subscribes',
            'ui_slots', 'translations'];
        foreach ($lists as $key) {
            if (!is_array($data[$key] ?? []) || !array_is_list($data[$key] ?? []) || count($data[$key] ?? []) > 64) {
                throw new ExtensionFailure('extension_manifest_invalid');
            }

            if (count(array_unique($data[$key] ?? [], SORT_REGULAR)) !== count($data[$key] ?? [])) {
                throw new ExtensionFailure('extension_manifest_invalid');
            }

            foreach ($data[$key] ?? [] as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 256) {
                    throw new ExtensionFailure('extension_manifest_invalid');
                }
            }
        }

        foreach ($data['providers'] ?? [] as $file) {
            self::path($file);
            if (!str_starts_with($file, 'src/') || !str_ends_with($file, '.php')) {
                throw new ExtensionFailure('extension_path_invalid');
            }
        }

        foreach ($data['migrations'] ?? [] as $migration) {
            if (preg_match('/^\d{4}_[a-z][a-z0-9_]{0,63}$/D', $migration) !== 1) {
                throw new ExtensionFailure('extension_manifest_invalid');
            }
        }

        $prefix = str_replace('/', '.', $data['id']) . '.';
        foreach (['routes', 'permissions', 'events'] as $key) {
            if (count(array_unique($data[$key] ?? [], SORT_REGULAR)) !== count($data[$key] ?? [])) {
                throw new ExtensionFailure('extension_manifest_invalid');
            }

            foreach ($data[$key] ?? [] as $value) {
                if (
                    !str_starts_with($value, $prefix)
                    || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $value) !== 1
                ) {
                    throw new ExtensionFailure('extension_namespace_invalid');
                }
            }
        }

        if (($data['translations'] ?? []) !== [] && !in_array('en', $data['translations'], true)) {
            throw new ExtensionFailure('extension_translation_invalid');
        }

        foreach ($data['translations'] ?? [] as $locale) {
            if (preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D', $locale) !== 1) {
                throw new ExtensionFailure('extension_manifest_invalid');
            }
        }

        (new ComposerVersionConstraint())->accepts('1.0.0', $data['engine']);
        (new ComposerVersionConstraint())->accepts('8.4.27', $data['php']);

        return new self($data['id'], $data['version'], $data);
    }

    public static function path(string $path): void
    {
        if (
            strlen($path) > 240 || preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./-]*$~D', $path) !== 1
            || str_contains($path, '..') || str_contains($path, '//') || str_ends_with($path, '/')
        ) {
            throw new ExtensionFailure('extension_path_invalid');
        }
    }

    public function table(string $local): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,25}$/D', $local) !== 1) {
            throw new ExtensionFailure('extension_namespace_invalid');
        }

        return 'ext_' . substr(hash('sha256', $this->id), 0, 32) . '_' . $local;
    }

    public function prefix(): string
    {
        return str_replace('/', '.', $this->id) . '.';
    }
}
