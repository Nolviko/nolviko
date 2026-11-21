<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\ReleaseId;
use Throwable;

/** Immutable validated snapshots and a single atomic activation pointer; a failed build cannot replace it. */
final readonly class ThemeBuildStore
{
    public function __construct(public string $root, public string $storage)
    {
    }

    public function directory(): string
    {
        $path = $this->storage . '/theme-builds';
        if (
            is_link($this->storage) || !is_dir($this->storage) || is_link($path)
            || (!is_dir($path) && !mkdir($path, 0700, true))
        ) {
            throw new TemplateException('theme_storage_unavailable');
        }
        ThemeCatalog::contained($this->storage, $path);
        return $path;
    }

    public function build(string $theme, array $input, int $owner): array
    {
        if ($owner < 1 || count(glob($this->directory() . '/build-*') ?: []) >= 128) {
            throw new TemplateException('theme_build_limit');
        }
        $catalog = new ThemeCatalog($this->root . '/themes');
        $chain = ThemeChain::resolve($catalog->directory, $theme);
        $core = ThemeFiles::core($this->root . '/views');
        $sources = [];
        $tokens = [];
        $assets = [];
        $warnings = [];
        $sourceBytes = 0;
        foreach (array_reverse($chain->themes) as $id) {
            $manifest = $catalog->manifest($id);
            if (version_compare(PHP_VERSION, $manifest->data['minimum_php'], '<')) {
                throw new TemplateException('theme_runtime_incompatible');
            }
            $files = ThemeFiles::read($catalog->directory . '/' . $id);
            $sourceBytes += array_sum(array_map(strlen(...), $files));
            if ($sourceBytes > 8388608) {
                throw new TemplateException('theme_package_limit');
            }
            $sources[$id] = $files;
            $raw = isset($files['tokens.json']) ? json_decode($files['tokens.json'], true, 16) : [];
            if (!is_array($raw)) {
                throw new TemplateException('theme_tokens_invalid');
            }
            $tokens = array_replace_recursive($tokens, $raw);
            foreach ($manifest->data['assets'] as $asset) {
                $body = $files['assets/' . $asset] ?? throw new TemplateException('theme_asset_invalid');
                $assets[$id . '/' . $asset] = ThemeAssetBuilder::build($asset, $body);
            }
            foreach (ThemeCompatibility::report($manifest, $files, $core) as $row) {
                if ($row['status'] !== 'compatible') {
                    $warnings[] = ['theme' => $id] + $row;
                }
            }
        }
        $tokens = ThemeTokens::clean(array_replace_recursive($tokens, $input));
        $data = ['theme' => $theme,
            'tokens' => $tokens,
            'owner' => $owner,
            'core_hash' => ThemeFiles::fingerprint($core),
            'release' => ReleaseId::fromFile($this->root . '/config/release.php'), 'warnings' => $warnings,
            'assets' => $assets, 'source_hash' => ThemeFiles::fingerprint($sources)];
        $hash = ThemeFiles::fingerprint($data);
        $final = $this->directory() . '/build-' . $hash;
        if (!is_dir($final)) {
            $stage = $this->directory() . '/stage-' . bin2hex(random_bytes(12));
            if (!mkdir($stage, 0700)) {
                throw new TemplateException('theme_storage_unavailable');
            }
            try {
                foreach ($sources as $id => $files) {
                    foreach ($files as $name => $body) {
                        self::file($stage . '/themes/' . $id . '/' . $name, $body);
                    }
                }
                foreach ($core as $name => $body) {
                    self::file($stage . '/core/' . $name, $body);
                }
                self::file($stage . '/build.json', json_encode($data, JSON_THROW_ON_ERROR));
                // Compile every effective template under the real strict policy before a pointer can reference it.
                $renderer = TwigFactory::create(
                    $this->root,
                    $stage . '/validation-no-cache',
                    TranslatorFactory::create($this->root . '/translations', 'en'),
                    activeTheme: $theme,
                    themesDirectory: $stage . '/themes',
                    coreViews: $stage . '/core',
                    cacheIdentity: $hash
                );
                $names = array_keys($core);
                foreach ($sources as $files) {
                    foreach (array_keys($files) as $name) {
                        if (str_starts_with($name, 'views/')) {
                            $names[] = substr($name, 6);
                        }
                    }
                }
                $renderer->warm(array_values(array_unique($names)));
                if (!rename($stage, $final)) {
                    throw new TemplateException('theme_build_failed');
                }
                TwigFactory::create(
                    $this->root,
                    $this->storage,
                    TranslatorFactory::create($this->root . '/translations', 'en'),
                    activeTheme: $theme,
                    themesDirectory: $final . '/themes',
                    coreViews: $final . '/core',
                    cacheIdentity: $hash
                )
                    ->warm(array_values(array_unique($names)));
            } finally {
                ThemeBuildCleanup::remove($this->directory(), $stage);
            }
        }
        $draft = ['build' => $hash, 'owner' => $owner, 'theme' => $theme, 'tokens' => $tokens,
            'base' => $this->pointer(), 'warnings' => count($warnings)];
        $this->atomic('draft-' . $owner . '.json', $draft);
        return $draft;
    }

    public function draft(int $owner): ?array
    {
        return $owner < 1 ? null : $this->read('draft-' . $owner . '.json');
    }

    public function get(string $hash): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new TemplateException('theme_build_invalid');
        }
        $path = $this->directory() . '/build-' . $hash;
        ThemeCatalog::contained($this->directory(), $path . '/build.json');
        if (is_link($path) || is_link($path . '/build.json') || filesize($path . '/build.json') > 4194304) {
            throw new TemplateException('theme_build_invalid');
        }
        $data = json_decode((string) file_get_contents($path . '/build.json'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !hash_equals($hash, ThemeFiles::fingerprint($data))) {
            throw new TemplateException('theme_build_invalid');
        }
        return $data + ['hash' => $hash, 'directory' => $path];
    }

    public function active(): ?array
    {
        try {
            $hash = $this->pointer();
            if ($hash === null) {
                return null;
            }
            $data = $this->get($hash);
            return $this->compatible($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function compatible(array $build): bool
    {
        return array_key_exists('release', $build)
            && $build['release'] === ReleaseId::fromFile($this->root . '/config/release.php')
            && hash_equals($build['core_hash'], ThemeFiles::fingerprint(ThemeFiles::core($this->root . '/views')));
    }

    public function publish(int $owner, string $expected): void
    {
        $lockFile = $this->directory() . '/publish.lock';
        if (is_link($lockFile)) {
            throw new TemplateException('theme_path_invalid');
        }
        $lock = fopen($lockFile, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new TemplateException('theme_publish_busy');
        }
        try {
            $draft = $this->draft($owner);
            if ($draft === null || !hash_equals($draft['build'], $expected) || $draft['base'] !== $this->pointer()) {
                throw new TemplateException('theme_draft_stale');
            }
            $build = $this->get($expected);
            if ($build['owner'] !== $owner || $build['warnings'] !== [] || !$this->compatible($build)) {
                throw new TemplateException('theme_publish_incompatible');
            }
            $this->atomic('active.json', ['build' => $expected, 'previous' => $this->pointer()]);
            $this->remember($build);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function hasPrevious(): bool
    {
        try {
            $previous = $this->read('active.json')['previous'] ?? null;
            return is_string($previous) && $this->compatible($this->get($previous));
        } catch (Throwable) {
            return false;
        }
    }

    public function rollback(string $expected): void
    {
        $lockFile = $this->directory() . '/publish.lock';
        if (is_link($lockFile)) {
            throw new TemplateException('theme_path_invalid');
        }
        $lock = fopen($lockFile, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new TemplateException('theme_publish_busy');
        }
        try {
            $pointer = $this->read('active.json');
            $previous = $pointer['previous'] ?? null;
            if ($this->pointer() !== $expected || !is_string($previous)) {
                throw new TemplateException('theme_draft_stale');
            }
            if (!$this->compatible($this->get($previous))) {
                throw new TemplateException('theme_publish_incompatible');
            }
            $this->atomic('active.json', ['build' => $previous, 'previous' => $expected]);
            $this->remember($this->get($previous));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Last published build of each theme (beta.18 roadmap 28): members may choose among these when the site allows
     * it. A theme appears once an administrator has published it at least once.
     *
     * @return array<string, string> Theme ID => build hash.
     */
    public function memberThemes(): array
    {
        try {
            $index = $this->read('members.json') ?? [];
        } catch (Throwable) {
            return [];
        }

        return array_filter($index, static fn(mixed $hash, mixed $theme): bool => is_string($theme)
            && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, ARRAY_FILTER_USE_BOTH);
    }

    /** The member-chosen theme's last published build, or null (then the site's build is used). */
    public function memberBuild(string $theme): ?array
    {
        $hash = $this->memberThemes()[$theme] ?? null;

        try {
            $build = $hash === null ? null : $this->get($hash);
        } catch (Throwable) {
            return null;
        }

        return $build !== null && $this->compatible($build) ? $build : null;
    }

    /** @param array<string, mixed> $build */
    private function remember(array $build): void
    {
        if (!is_string($build['theme'] ?? null) || !is_string($build['hash'] ?? null)) {
            return;
        }

        try {
            $index = $this->memberThemes();
            $index[$build['theme']] = $build['hash'];
            $this->atomic('members.json', $index);
        } catch (Throwable) {
            // The member choice list is a convenience; publishing already succeeded.
        }
    }

    public function pointer(): ?string
    {
        $data = $this->read('active.json');
        $hash = $data['build'] ?? null;
        if ($hash !== null && (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1)) {
            throw new TemplateException('theme_build_invalid');
        }
        return $hash;
    }

    private function read(string $name): ?array
    {
        $file = $this->directory() . '/' . $name;
        if (!is_file($file)) {
            return null;
        }
        if (is_link($file) || filesize($file) > 65536) {
            throw new TemplateException('theme_build_invalid');
        }
        $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : throw new TemplateException('theme_build_invalid');
    }

    private function atomic(string $name, array $data): void
    {
        $path = $this->directory() . '/' . $name;
        if (is_link($path)) {
            throw new TemplateException('theme_path_invalid');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(12));
        self::file($temporary, json_encode($data, JSON_THROW_ON_ERROR));
        if (!rename($temporary, $path)) {
            throw new TemplateException('theme_activation_failed');
        }
    }

    private static function file(string $path, string $body): void
    {
        $directory = dirname($path);
        if (
            (!is_dir($directory) && !mkdir($directory, 0700, true)) || is_link($path)
            || file_put_contents($path, $body, LOCK_EX) !== strlen($body)
        ) {
            throw new TemplateException('theme_build_failed');
        }
        chmod($path, 0600);
    }
}
