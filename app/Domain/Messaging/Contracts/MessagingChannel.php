<?php

namespace App\Domain\Messaging\Contracts;

use App\Domain\Messaging\DTOs\SendResult;

/**
 * One way of reaching a phone.
 *
 * Same shape as PaymentDriver, and for the same reason: the provider should be
 * a settings choice, not a code change. Mova sends OTPs, payment confirmations
 * and reminders, and which carrier delivers them best into +242 will change.
 *
 * A channel reports what it CAN do (`supports`) rather than being asked to
 * guess — MessagingService walks a failover chain, and a channel that silently
 * no-ops on WhatsApp would make the chain stop at a link that never delivered.
 */
interface MessagingChannel
{
    /** sms | whatsapp — what this channel can actually deliver. */
    public function supports(string $kind): bool;

    /**
     * Sends one message.
     *
     * @param  string  $to  E.164, with the +.
     * @param  array<string, string>  $variables  For template-based channels.
     */
    public function send(string $to, string $kind, string $body, array $variables = []): SendResult;

    /** Credential check for Settings → "Tester". */
    public function healthCheck(array $credentials): SendResult;
}
