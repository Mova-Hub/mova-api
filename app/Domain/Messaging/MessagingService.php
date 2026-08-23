<?php

namespace App\Domain\Messaging;

use App\Domain\Messaging\Channels\InfobipChannel;
use App\Domain\Messaging\Channels\LogChannel;
use App\Domain\Messaging\Channels\TwilioChannel;
use App\Domain\Messaging\Contracts\MessagingChannel;
use App\Domain\Messaging\DTOs\SendResult;
use App\Domain\Settings\Facades\Settings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reaching a customer, with a fallback.
 *
 * Same registry pattern as payments: the provider is a settings choice, and
 * adding one is a class plus a line in `CHANNELS`.
 *
 * **The failover chain is the reason this class exists.** WhatsApp reaches
 * people who are out of SMS credit and costs less; SMS reaches people who do
 * not use WhatsApp and works on a feature phone. Neither alone is good enough
 * for an OTP, and an OTP that does not arrive is an account nobody can create.
 * The chain is configured per event type, so a reminder can be WhatsApp-only
 * while an OTP tries everything.
 */
class MessagingService
{
    /** Provider key → channel class. The only place a provider needs code. */
    private const CHANNELS = [
        'infobip' => InfobipChannel::class,
        'twilio' => TwilioChannel::class,
        'log' => LogChannel::class,
    ];

    /**
     * Sends a message, walking the chain until one lands.
     *
     * @param  string  $event  otp | payment | reminder — selects the chain.
     * @param  array<string, string>  $variables  Template placeholders.
     */
    public function send(string $to, string $event, string $body, array $variables = []): SendResult
    {
        $chain = $this->chainFor($event);
        $channel = $this->channel();

        $last = SendResult::failed('none', 'Aucun canal configuré.', retryable: false);

        foreach ($chain as $kind) {
            // `push` belongs to Firebase, not to this service. Skipped here so
            // a chain listing it does not silently fail at the last link.
            if ($kind === 'push' || ! $channel->supports($kind)) {
                continue;
            }

            if (! $this->kindEnabled($kind)) {
                continue;
            }

            try {
                $result = $channel->send($to, $kind, $body, $variables);
            } catch (Throwable $e) {
                report($e);
                $result = SendResult::failed($kind, $e->getMessage());
            }

            if ($result->ok) {
                return $result;
            }

            $last = $result;

            /*
             * A bad number fails identically everywhere. Stopping here saves
             * two more providers' rate limits and, more usefully, keeps the
             * error specific — "numéro invalide" rather than a chain of
             * timeouts that says nothing about the cause.
             */
            if (! $result->retryable) {
                break;
            }

            Log::warning('Messaging channel failed, trying next', [
                'event' => $event,
                'kind' => $kind,
                'error' => $result->error,
                // NEVER the destination in full, and never the body.
                'to' => $this->mask($to),
            ]);
        }

        return $last;
    }

    /**
     * An OTP.
     *
     * A named method rather than a `send()` call site, so the one message that
     * must never be logged in full has exactly one place it can be composed.
     * The code is passed to the channel and nowhere else.
     */
    public function sendOtp(string $to, string|int $code): SendResult
    {
        return $this->send(
            $to,
            'otp',
            "Mova : votre code de vérification est {$code}. Il est valable 10 minutes.",
            [
                '_template' => Settings::string('notifications.otp_template', 'mova_otp'),
                'code' => (string) $code,
            ],
        );
    }

    public function channel(): MessagingChannel
    {
        $key = Settings::string('notifications.channel_provider', 'log');
        $class = self::CHANNELS[$key] ?? LogChannel::class;

        return app($class);
    }

    /** @return array<int, string> */
    public function chainFor(string $event): array
    {
        $chain = Settings::get("notifications.{$event}_chain");

        if (is_array($chain) && $chain !== []) {
            return $chain;
        }

        // WhatsApp first — cheaper, richer, and it reaches people who have run
        // out of SMS credit, which in this market is a lot of people.
        return ['whatsapp', 'sms'];
    }

    private function kindEnabled(string $kind): bool
    {
        return match ($kind) {
            'whatsapp' => Settings::bool('notifications.whatsapp_enabled', false),
            'sms' => Settings::bool('notifications.sms_enabled', false),
            default => false,
        };
    }

    private function mask(string $phone): string
    {
        return str_repeat('*', max(0, strlen($phone) - 4)) . substr($phone, -4);
    }
}
