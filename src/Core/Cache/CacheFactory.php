<?php

declare(strict_types=1);

namespace Nolviko\Core\Cache;

use InvalidArgumentException;
use Throwable;

/**
 * Hands out one cache per namespace (`routes`, `permissions`, an extension's own ID, ...). Data lives in
 * `<storage>/cache/data/`, outside `public/` and denied by `storage/.htaccess`; without a storage directory
 * every cache is a permanent miss.
 */
final class CacheFactory
{
    public const SUBDIRECTORY = 'cache/data';

    public function __construct(private readonly string $storageDirectory)
    {
    }

    /**
     * @throws InvalidArgumentException `cache_namespace_invalid` for a name outside FileCache::NAMESPACE_PATTERN.
     */
    public function namespace(string $name): Cache
    {
        if (preg_match(FileCache::NAMESPACE_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('cache_namespace_invalid');
        }

        $directory = is_dir($this->storageDirectory)
            ? $this->storageDirectory . '/' . self::SUBDIRECTORY . '/' . $name
            : null;

        $local = fn(string $epoch): Cache => new FileCache(
            $directory === null ? null : dirname($directory) . '/'
                . substr(hash('sha256', $name . ':' . $epoch), 0, 48),
            $this->storageDirectory,
        );
        $settings = new RedisSettingsStore($this->storageDirectory);
        $state = $settings->status();

        if (!$state['enabled'] || !in_array($name, $state['namespaces'], true)) {
            return $state['epoch'] === 'filesystem' ? new FileCache($directory, $this->storageDirectory)
                : $local($state['epoch']);
        }

        try {
            $options = $settings->options();

            if ($options === null) {
                return $local($state['epoch']);
            }

            $prefix = 'nolviko:' . substr(hash('sha256', $this->storageDirectory), 0, 24)
                . ':' . $state['epoch'] . ':' . $name;
            $remote = new RedisCache(
                new RedisConnection($options),
                $prefix,
                $settings->key(),
                $local($state['epoch'] . '.locks')
            );

            return new FailoverCache($remote, $settings, $state['epoch'], $local);
        } catch (Throwable) {
            return $local($state['epoch']);
        }
    }
}
