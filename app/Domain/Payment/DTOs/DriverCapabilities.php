<?php

namespace App\Domain\Payment\DTOs;

/**
 * What a driver can actually do.
 *
 * Declared rather than assumed, because the callers genuinely differ. Cash
 * cannot be polled, the card stub cannot refund, and Mova Credit settles
 * synchronously so it has no webhook to verify. Asking a driver instead of
 * branching on its class name is what keeps `PaymentService` free of a `match`
 * over provider codes — the exact thing that made adding a provider a code
 * change before.
 */
final class DriverCapabilities
{
    public function __construct(
        /** Can start a collection at all. False for a stub. */
        public readonly bool $collect = true,
        public readonly bool $refund = false,
        /** Has a status endpoint worth polling. Drives `payments:reconcile`. */
        public readonly bool $statusPoll = false,
        /** Delivers callbacks, so a webhook route should exist for it. */
        public readonly bool $webhook = false,
        /**
         * Settles immediately — no prompt on a handset, no waiting state.
         *
         * Only Mova Credit and manual back-office entries. The payment sheet
         * uses this to skip its "confirmez sur votre téléphone" screen, which
         * would otherwise appear for a fifth of a second and look like a bug.
         */
        public readonly bool $synchronous = false,
    ) {}

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'collect' => $this->collect,
            'refund' => $this->refund,
            'status_poll' => $this->statusPoll,
            'webhook' => $this->webhook,
            'synchronous' => $this->synchronous,
        ];
    }
}
