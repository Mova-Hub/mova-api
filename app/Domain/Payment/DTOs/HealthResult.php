<?php

namespace App\Domain\Payment\DTOs;

/**
 * The answer to Settings → Paiement → "Tester".
 *
 * Worth its own type rather than a boolean because the useful part is *why*.
 * "Échec" tells an operator nothing; "401 — la clé d'abonnement est refusée"
 * tells them which of the four credentials they pasted wrong, which is the
 * difference between a two-minute fix and a support ticket to MTN.
 *
 * The message is shown to staff, so it may name a provider status code — unlike
 * ChargeResult's, which is shown to a client and must never leak one.
 */
final class HealthResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        /** Round-trip time, so a working-but-slow provider is visible. */
        public readonly ?int $latencyMs = null,
        public readonly array $details = [],
    ) {}

    public static function ok(string $message = 'Connexion établie.', ?int $latencyMs = null): self
    {
        return new self(true, $message, $latencyMs);
    }

    public static function fail(string $message, array $details = []): self
    {
        return new self(false, $message, null, $details);
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'latency_ms' => $this->latencyMs,
            'details' => $this->details,
        ];
    }
}
