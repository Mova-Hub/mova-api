<?php

namespace App\Domain\Pass\DTOs;

use Carbon\CarbonImmutable;

/**
 * What a card asserts: this subscriber, valid until this date, signed by this key.
 *
 * Immutable by construction. An entitlement whose fields could be changed after
 * signing is a signature that proves nothing about the value you later read, so
 * the object that goes into the signer is the object that comes out of it.
 *
 * Note what is NOT here: no name, no phone, no balance. The chip carries an
 * identifier plus an expiry, nothing more (PRD §2, non-goals). It is
 * world-readable, so anything on it is public.
 */
final class Entitlement
{
    public function __construct(
        public readonly string $version,
        public readonly string $keyId,
        /** base64url of the subscriber's 16 raw UUID bytes — 22 chars. */
        public readonly string $subscriberId,
        /** Whole days since the Unix epoch, UTC. */
        public readonly int $expiryDays,
    ) {}

    public static function fromDate(
        string $version,
        string $keyId,
        string $subscriberId,
        CarbonImmutable $expiresAt,
    ): self {
        return new self(
            $version,
            $keyId,
            $subscriberId,
            // intdiv on the UTC timestamp: the card stores a DAY, so a
            // subscription expiring at 23:59 local is still valid all that day
            // wherever the inspector's clock happens to sit.
            (int) intdiv($expiresAt->utc()->getTimestamp(), 86400),
        );
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->expiryDays * 86400);
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expiresAt()->lt($now ?? CarbonImmutable::now());
    }
}
