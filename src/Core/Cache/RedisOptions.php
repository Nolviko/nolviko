<?php

declare(strict_types=1);

namespace Nolviko\Core\Cache;

use InvalidArgumentException;
use Nolviko\Core\Config\Secret;
use SensitiveParameter;

/** Explicit IP endpoint, TLS for non-loopback, and secrets that cannot appear in dumps. */
final readonly class RedisOptions
{
    public Secret $password;

    public function __construct(
        public string $host,
        public int $port,
        #[SensitiveParameter] string $password = '',
        public string $username = '',
        public int $database = 0,
        public bool $tls = true,
        public string $peerName = '',
        public array $namespaces = ['theme-render', 'community-presence'],
    ) {
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        $loopback = $host === '127.0.0.1' || $host === '::1';

        foreach ($namespaces as $namespace) {
            if (!is_string($namespace)) {
                throw new InvalidArgumentException('redis_options_invalid');
            }
        }

        if (
            $ip === false || $port < 1 || $port > 65535 || $database < 0 || $database > 15
            || (!$tls && !$loopback) || str_starts_with($host, '169.254.') || str_starts_with($host, 'fe80:')
            || $host === '0.0.0.0' || $host === '::' || strlen($password) > 512 || strlen($username) > 128
            || preg_match('/[\x00-\x20\x7f]/', $host . $username . $peerName) === 1
            || ($peerName !== '' && preg_match('/^[a-zA-Z0-9.-]{1,253}$/D', $peerName) !== 1)
            || array_diff($namespaces, ['theme-render', 'community-presence']) !== []
        ) {
            throw new InvalidArgumentException('redis_options_invalid');
        }

        $this->password = new Secret($password);
    }
}
