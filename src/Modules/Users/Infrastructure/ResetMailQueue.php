<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Modules\Users\Domain\AccountMail;
use Nolviko\Modules\Users\Domain\User;
use Nolviko\Modules\Users\Domain\UserStatus;

/** The queue contains neither the address nor a usable reset link. Retries deliver the same link. */
final readonly class ResetMailQueue
{
    public const EVENT = 'account.reset_requested';

    /** How far back the job is dated so it runs before ordinary background work. */
    public const PRIORITY_DAYS = 7;

    public function __construct(private Connection $db, private Secret $secret)
    {
    }

    public function enqueue(?User $user, ?string $network, DateTimeImmutable $now, string $locale): void
    {
        $nonce = bin2hex(random_bytes(32));
        $this->db->transactional(function () use ($nonce, $user, $network, $now, $locale): void {
            if ($user !== null) {
                (new DbalResetTokens($this->db))->store($this->token($nonce), $user->id, $network, $now);
            }
            $payload = [
                'nonce' => $nonce,
                'locale' => $locale,
                'recipient' => $this->recipient($nonce, $user?->email ?? ''),
            ];
            // Ahead of the backlog (the queue runs oldest first): a member waiting for a reset link should not wait
            // behind hundreds of notification mails on a site without cron.
            (new Outbox($this->db))->record(
                self::EVENT,
                'request',
                0,
                $payload,
                self::EVENT . ':' . $nonce,
                $now->modify('-' . self::PRIORITY_DAYS . ' days'),
            );
        });
    }

    public function deliver(OutboxLease $lease, AccountMail $mail): bool
    {
        $payload = json_decode($lease->payload, true, 4, JSON_THROW_ON_ERROR);
        $token = $this->token($payload['nonce']);
        $id = (new DbalResetTokens($this->db))->peek($token, new DateTimeImmutable());
        $user = $id === null ? null : (new DbalUserRepository($this->db))->findById($id);
        if (
            $user !== null && $user->status === UserStatus::Active
            && hash_equals($payload['recipient'], $this->recipient($payload['nonce'], $user->email))
        ) {
            $mail->passwordReset($user->email, $user->username, $token);
        }
        return true;
    }

    private function recipient(string $nonce, string $address): string
    {
        return hash_hmac('sha256', 'recipient:' . $nonce . ':' . $address, $this->secret->reveal());
    }

    private function token(string $nonce): string
    {
        $bytes = hash_hmac('sha256', self::EVENT . ':' . $nonce, $this->secret->reveal(), true);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
