<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Closure;
use JsonException;
use Nolviko\Core\Updates\RecoveryGrantStore;
use Nolviko\Core\Updates\UpdateStage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Frozen-runtime transport only. No public route is installed until the trusted dispatcher is configured. */
final class RecoveryGateway implements RouteHandler
{
    /**
     * Every command RecoveryDispatcher::actions() offers. `backup` was missing (found 2026-11-19 on
     * community.nolviko.com): the constructor then refused its own configuration and every recovery request
     * answered 503, so no update could pass the recovery page. RecoveryGatewayFlowTest drives it end to end.
     */
    private const ACTIONS = ['status', 'backup', 'resume', 'migrate', 'smoke', 'finish'];

    /**
     * @param Closure(Request, int): bool $authorize Requires current admin identity and recent reauthentication.
     * @param array<string, Closure(string, int): array<string, mixed>> $actions Server-defined job commands only.
     */
    public function __construct(
        private readonly RecoveryGrantStore $grants,
        private readonly string $origin,
        private readonly Closure $authorize,
        private readonly array $actions,
    ) {
        $parts = parse_url($origin);

        if (
            $parts === false || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '') || !(($actions['status'] ?? null) instanceof Closure)
        ) {
            throw new RuntimeException('recovery_gateway_configuration_invalid');
        }

        foreach ($actions as $name => $action) {
            if (!in_array($name, self::ACTIONS, true) || !($action instanceof Closure)) {
                throw new RuntimeException('recovery_gateway_configuration_invalid');
            }
        }
    }

    public function handle(Request $request, array $parameters): Response
    {
        if (
            !$request->isMethod('POST') || !$request->isSecure() || $request->query->count() !== 0
            || $request->headers->get('Origin') !== $this->origin
            || strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0])) !== 'application/json'
        ) {
            return self::reply(['error' => 'recovery_request_refused'], 403);
        }

        $body = $request->getContent();
        $sequence = (string) $request->headers->get('X-Nolviko-Recovery-Sequence', '');

        if (strlen($body) > 1024 || preg_match('/^(0|[1-9][0-9]{0,8})$/D', $sequence) !== 1) {
            return self::reply(['error' => 'recovery_request_invalid'], 400);
        }

        try {
            $data = json_decode($body, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::reply(['error' => 'recovery_request_invalid'], 400);
        }

        $name = is_array($data) ? ($data['action'] ?? null) : null;

        if (!is_string($name) || ($name !== 'revoke' && !isset($this->actions[$name]))) {
            return self::reply(['error' => 'recovery_action_unavailable'], 400);
        }

        try {
            $used = $this->grants->useGrant(
                (string) $request->headers->get('X-Nolviko-Recovery-Grant', ''),
                (string) $request->headers->get('X-Nolviko-Recovery-Code', ''),
                (string) $request->headers->get('X-Nolviko-Recovery-Nonce', ''),
                (int) $sequence,
                fn(int $actor): bool => ($this->authorize)($request, $actor) === true,
                $name === 'revoke' ? static fn(): array => [] : $this->actions[$name],
                $name === 'revoke',
            );
        } catch (RuntimeException $failure) {
            // 2026-11-27 (RC1 final run): a step whose request died keeps its lease for up to two minutes; the
            // next request is "busy, try again", not a refusal, so the update page waits instead of failing.
            $busy = in_array($failure->getMessage(), ['recovery_grant_busy', 'update_claim_refused'], true);

            return self::reply(['error' => $busy ? 'recovery_busy' : 'recovery_request_refused'], $busy ? 409 : 403);
        } catch (Throwable) {
            // No exception text, credentials, filesystem paths or request headers enter the response.
            return self::reply(['error' => 'recovery_step_failed'], 503);
        }

        if ($used['conflict']) {
            return self::reply(['error' => 'recovery_sequence_conflict', 'next_sequence' => $used['sequence']], 409);
        }

        $safe = ['next_sequence' => $used['sequence'], 'revoked' => $name === 'revoke'];
        $result = $used['result'];

        if (isset($result['stage']) && is_string($result['stage']) && UpdateStage::tryFrom($result['stage']) !== null) {
            $safe['stage'] = $result['stage'];
        }

        if (isset($result['cursor']) && is_int($result['cursor']) && $result['cursor'] >= 0) {
            $safe['cursor'] = $result['cursor'];
        }

        if (isset($result['complete']) && is_bool($result['complete'])) {
            $safe['complete'] = $result['complete'];
        }

        if (
            isset($result['next_action']) && in_array($result['next_action'], self::ACTIONS, true)
            && $result['next_action'] !== 'status'
        ) {
            $safe['next_action'] = $result['next_action'];
        }

        return self::reply($safe, 200);
    }

    /** @param array<string, mixed> $data */
    private static function reply(array $data, int $status): Response
    {
        return new Response(json_encode($data, JSON_THROW_ON_ERROR), $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }
}
