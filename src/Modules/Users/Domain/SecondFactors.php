<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Extensions\AuthChallenge;
use Nolviko\Core\Extensions\SecondFactorProviders;
use SensitiveParameter;
use Throwable;

/**
 * Members' second sign-in steps (2026-11-15, ADR-029). Core keeps the enrolments, each provider's state sealed
 * with a key derived from the site secret and bound to member and provider, and ten single-use recovery codes
 * per member (SHA-256 only). A provider whose extension is not enabled is skipped: the member then signs in with
 * the password alone (owner decision 2026-11-15) and the admin dashboard counts such members.
 */
final class SecondFactors
{
    public const RECOVERY_CODES = 10;

    private const STATE_PREFIX = 'v1.';

    private readonly Closure $clock;

    /**
     * @param (Closure(int $userId, string $event): void)|null $notify Mail to the member: enabled, disabled, reset.
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly Connection $db,
        private readonly SecondFactorProviders $providers,
        private readonly Secret $siteSecret,
        private readonly ?Closure $notify = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock
            ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function available(): bool
    {
        return $this->providers->ids() !== [];
    }

    /** @return list<string> Provider IDs whose extension is registered. */
    public function providerIds(): array
    {
        return $this->providers->ids();
    }

    public function providers(): SecondFactorProviders
    {
        return $this->providers;
    }

    /** @return list<string> The member's enrolled providers that are enabled now. */
    public function active(int $userId): array
    {
        $enrolled = $this->db->fetchFirstColumn(
            'SELECT provider FROM user_second_factors WHERE user_id = ? ORDER BY provider',
            [$userId],
        );

        return array_values(array_filter(
            array_map('strval', $enrolled),
            fn(string $provider): bool => $this->providers->has($provider),
        ));
    }

    /** Any enrolment, also for a provider that is not enabled now. */
    public function hasAny(int $userId): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM user_second_factors WHERE user_id = ?', [$userId]) !== false;
    }

    public function enrolled(int $userId, string $provider): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM user_second_factors WHERE user_id = ? AND provider = ?',
            [$userId, $provider],
        ) !== false;
    }

    /** Members with an enrolment whose provider is not enabled; they sign in with the password alone. */
    public function membersWithoutProvider(): int
    {
        $ids = $this->providers->ids();
        $sql = 'SELECT COUNT(DISTINCT user_id) FROM user_second_factors';
        if ($ids !== []) {
            $sql .= ' WHERE provider NOT IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
        }

        return (int) $this->db->fetchOne($sql, $ids);
    }

    /**
     * Stores the member's state for a provider the member has just proven (the provider checked a first code).
     *
     * @return list<string> New recovery codes to show once, or [] when the member already has unused ones.
     */
    public function enroll(int $userId, string $provider, #[SensitiveParameter] string $state): array
    {
        if (!$this->providers->has($provider)) {
            throw new RegistrationRejected(['second_factor_unavailable']);
        }

        $now = $this->now();
        $codes = [];
        $this->db->transactional(function () use ($userId, $provider, $state, $now, &$codes): void {
            $this->db->delete('user_second_factors', ['user_id' => $userId, 'provider' => $provider]);
            $this->db->insert('user_second_factors', [
                'user_id' => $userId, 'provider' => $provider,
                'state' => $this->seal($userId, $provider, $state), 'created_at' => $now,
            ]);
            if ($this->recoveryLeft($userId) === 0) {
                $codes = $this->replaceRecoveryCodes($userId);
            }
        });
        $this->tell($userId, 'enabled');

        return $codes;
    }

    /** Turns one method off; the recovery codes go with the last one. */
    public function remove(int $userId, string $provider, string $event = 'disabled'): void
    {
        $this->db->transactional(function () use ($userId, $provider): void {
            $this->db->delete('user_second_factors', ['user_id' => $userId, 'provider' => $provider]);
            if ($this->db->fetchOne('SELECT 1 FROM user_second_factors WHERE user_id = ?', [$userId]) === false) {
                $this->db->delete('user_recovery_codes', ['user_id' => $userId]);
            }
        });
        $this->tell($userId, $event);
    }

    /** An administrator's reset for a member who lost the device and the codes (audited by the caller). */
    public function reset(int $userId): void
    {
        $this->db->transactional(function () use ($userId): void {
            $this->db->delete('user_second_factors', ['user_id' => $userId]);
            $this->db->delete('user_recovery_codes', ['user_id' => $userId]);
        });
        $this->tell($userId, 'reset');
    }

    public function recoveryLeft(int $userId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId],
        );
    }

    /** @return list<string> Ten new codes; every older code stops working. */
    public function newRecoveryCodes(int $userId): array
    {
        return $this->db->transactional(fn(): array => $this->replaceRecoveryCodes($userId));
    }

    /**
     * Checks a code from any of the member's enabled methods, or one unused recovery code. A provider's new state
     * is stored only if nobody changed it meanwhile, so the same code cannot pass twice in parallel requests.
     *
     * @param string $purpose One of AuthChallenge's purposes (sign_in, admin_reauth, remove, ...).
     */
    public function verify(int $userId, string $purpose, #[SensitiveParameter] string $response): bool
    {
        $response = trim($response);
        if ($response === '' || strlen($response) > 64) {
            return false;
        }

        foreach ($this->active($userId) as $provider) {
            if ($this->verifyWith($userId, $provider, $purpose, $response)) {
                return true;
            }
        }

        return $this->useRecoveryCode($userId, $response);
    }

    /** Checks a code from one method only (no recovery codes), e.g. to confirm turning that method off. */
    public function verifyWith(
        int $userId,
        string $provider,
        string $purpose,
        #[SensitiveParameter] string $response,
    ): bool {
        $row = $this->db->fetchAssociative(
            'SELECT state FROM user_second_factors WHERE user_id = ? AND provider = ?',
            [$userId, $provider],
        );
        if ($row === false || !$this->providers->has($provider)) {
            return false;
        }

        $state = $this->open($userId, $provider, (string) $row['state']);
        if ($state === null) {
            return false;
        }

        $challenge = new AuthChallenge(
            $this->providers->owner($provider),
            $userId,
            $purpose,
            bin2hex(random_bytes(32)),
            ($this->clock)()->getTimestamp() + 300,
        );
        try {
            $next = $this->providers->get($provider)->verify($challenge, $state, $response);
        } catch (Throwable) {
            return false;
        }
        if ($next === null) {
            return false;
        }

        return $this->db->executeStatement(
            'UPDATE user_second_factors SET state = ?, used_at = ? WHERE user_id = ? AND provider = ? AND state = ?',
            [$this->seal($userId, $provider, $next), $this->now(), $userId, $provider, $row['state']],
        ) === 1;
    }

    private function useRecoveryCode(int $userId, string $response): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $response));
        if (strlen($normalized) !== 10) {
            return false;
        }

        return $this->db->executeStatement(
            'UPDATE user_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$this->now(), $userId, hash('sha256', $normalized)],
        ) === 1;
    }

    /** @return list<string> */
    private function replaceRecoveryCodes(int $userId): array
    {
        $this->db->delete('user_recovery_codes', ['user_id' => $userId]);
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];
        $now = $this->now();
        while (count($codes) < self::RECOVERY_CODES) {
            $code = '';
            for ($i = 0; $i < 10; ++$i) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (isset($codes[$code])) {
                continue;
            }
            $codes[$code] = true;
            $this->db->insert('user_recovery_codes', [
                'user_id' => $userId, 'code_hash' => hash('sha256', $code), 'created_at' => $now,
            ]);
        }

        return array_map(
            static fn(string $code): string => substr($code, 0, 5) . '-' . substr($code, 5),
            array_keys($codes),
        );
    }

    private function seal(int $userId, string $provider, #[SensitiveParameter] string $state): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = json_encode(['u' => $userId, 'p' => $provider, 's' => $state], JSON_THROW_ON_ERROR);

        return self::STATE_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    private function open(int $userId, string $provider, string $sealed): ?string
    {
        $raw = str_starts_with($sealed, self::STATE_PREFIX)
            ? base64_decode(substr($sealed, strlen(self::STATE_PREFIX)), true) : false;
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        $data = is_string($plain) ? json_decode($plain, true, 4) : null;

        return is_array($data) && ($data['u'] ?? null) === $userId && ($data['p'] ?? null) === $provider
            && is_string($data['s'] ?? null) ? $data['s'] : null;
    }

    private function key(): string
    {
        return hash_hkdf(
            'sha256',
            $this->siteSecret->reveal(),
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            'nolviko second factor',
        );
    }

    private function tell(int $userId, string $event): void
    {
        if ($this->notify === null) {
            return;
        }

        try {
            ($this->notify)($userId, $event);
        } catch (Throwable) {
            // A notice that cannot be sent never undoes the change; mail failures are logged by the mailer.
        }
    }

    private function now(): string
    {
        return ($this->clock)()->format('Y-m-d H:i:s.u');
    }
}
