<?php

namespace App\Domain\Messaging\DTOs;

/**
 * What happened when we tried to reach someone.
 *
 * Carries `retryable` because the failover chain needs to distinguish two very
 * different failures: "WhatsApp is down" (try SMS) from "this number is not on
 * WhatsApp" (also try SMS, but never retry WhatsApp for this person) from
 * "the number is malformed" (stop — every channel will fail identically, and
 * walking the chain just burns three providers' rate limits on a typo).
 */
final class SendResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $channel,
        public readonly ?string $reference = null,
        public readonly ?string $error = null,
        /** False for a bad number: the next channel will fail the same way. */
        public readonly bool $retryable = true,
    ) {}

    public static function sent(string $channel, ?string $reference = null): self
    {
        return new self(true, $channel, $reference);
    }

    public static function failed(string $channel, string $error, bool $retryable = true): self
    {
        return new self(false, $channel, null, $error, $retryable);
    }
}
