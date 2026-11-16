<?php

declare(strict_types=1);

namespace Nolviko\Core\Cache;

use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Small RESP2 command allowlist, verified TLS, bounded bytes and a per-command wall-clock deadline. */
final class RedisConnection
{
    private mixed $stream = null;

    private float $deadline = 0;

    public function __construct(
        private readonly RedisOptions $options,
        private readonly ?string $caFile = null,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function command(array $arguments): mixed
    {
        if (
            !in_array($arguments[0] ?? '', ['PING', 'GET', 'SET', 'DEL', 'AUTH', 'SELECT'], true)
            || count($arguments) > 8
        ) {
            throw new RuntimeException('redis_command_invalid');
        }

        $this->deadline = microtime(true) + 0.35;

        try {
            if (!is_resource($this->stream)) {
                $this->connect();
            }

            return $this->exchange($arguments);
        } catch (Throwable) {
            $this->close();
            throw new RuntimeException('redis_unavailable');
        }
    }

    private function connect(): void
    {
        $host = str_contains($this->options->host, ':') ? '[' . $this->options->host . ']' : $this->options->host;
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $this->options->peerName === '' ? $this->options->host : $this->options->peerName];

        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }

        $this->stream = @stream_socket_client(
            ($this->options->tls ? 'tls' : 'tcp') . '://' . $host
            . ':' . $this->options->port,
            $errno,
            $error,
            0.15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $ssl])
        );

        if (!is_resource($this->stream)) {
            throw new RuntimeException('redis_unavailable');
        }

        stream_set_timeout($this->stream, 0, 150000);

        if (!$this->options->password->isEmpty()) {
            $auth = $this->options->username === '' ? ['AUTH', $this->options->password->reveal()]
                : ['AUTH', $this->options->username, $this->options->password->reveal()];

            if ($this->exchange($auth) !== 'OK') {
                throw new RuntimeException('redis_auth_failed');
            }
        }

        if ($this->options->database !== 0 && $this->exchange(['SELECT', (string) $this->options->database]) !== 'OK') {
            throw new RuntimeException('redis_database_failed');
        }
    }

    private function exchange(#[SensitiveParameter] array $arguments): mixed
    {
        $message = '*' . count($arguments) . "\r\n";

        foreach ($arguments as $argument) {
            if (!is_string($argument) || strlen($argument) > 1048576) {
                throw new RuntimeException('redis_command_invalid');
            }

            $message .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        $offset = 0;

        while ($offset < strlen($message)) {
            $this->guard();
            $written = @fwrite($this->stream, substr($message, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('redis_write_failed');
            }

            $offset += $written;
        }

        $this->guard();
        $line = @fgets($this->stream, 4097);

        if (!is_string($line) || !str_ends_with($line, "\r\n") || strlen($line) > 4096) {
            throw new RuntimeException('redis_response_invalid');
        }

        $type = $line[0];
        $body = substr($line, 1, -2);

        if ($type === '+') {
            return $body;
        }

        if ($type === ':' && preg_match('/^-?[0-9]{1,18}$/D', $body) === 1) {
            return (int) $body;
        }

        if ($type !== '$' || preg_match('/^-?[0-9]{1,8}$/D', $body) !== 1 || (int) $body > 1048576) {
            throw new RuntimeException('redis_response_invalid');
        }

        $size = (int) $body;

        if ($size === -1) {
            return null;
        }

        if ($size < 0) {
            throw new RuntimeException('redis_response_invalid');
        }

        $data = '';

        while (strlen($data) < $size + 2) {
            $this->guard();
            $part = @fread($this->stream, min(65536, $size + 2 - strlen($data)));

            if (!is_string($part) || $part === '') {
                throw new RuntimeException('redis_read_failed');
            }

            $data .= $part;
        }

        if (!str_ends_with($data, "\r\n")) {
            throw new RuntimeException('redis_response_invalid');
        }

        return substr($data, 0, $size);
    }

    private function guard(): void
    {
        if (microtime(true) > $this->deadline || !is_resource($this->stream)) {
            throw new RuntimeException('redis_budget');
        }
    }

    private function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }
}
