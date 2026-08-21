<?php

namespace App\Domain\Payment\DTOs;

use App\Domain\Payment\Enums\PaymentStatus;

/**
 * What a provider said when asked to collect money.
 *
 * Deliberately NOT a boolean. Mobile money is asynchronous: the useful answer to
 * "did it work?" is almost always "the client is being prompted on their phone",
 * which is neither success nor failure, and collapsing that into true/false is
 * how an app ends up either charging twice or declaring failure while the money
 * is still moving.
 */
final class ChargeResult
{
    public function __construct(
        public readonly PaymentStatus $status,
        /** The provider's own id, for reconciliation and support. */
        public readonly ?string $reference = null,
        /** Shown to the client. French, and never a raw provider code. */
        public readonly ?string $message = null,
        /** Anything the provider returned that is worth keeping. */
        public readonly array $meta = [],
    ) {}

    public static function processing(?string $reference, ?string $message = null): self
    {
        return new self(PaymentStatus::Processing, $reference, $message);
    }

    public static function failed(string $message, array $meta = []): self
    {
        return new self(PaymentStatus::Failed, null, $message, $meta);
    }
}
