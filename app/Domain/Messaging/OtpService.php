<?php

namespace App\Domain\Messaging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One-time codes: issuing them, delivering them, and checking them back.
 *
 * Every OTP in the system goes through here. Before this existed the two flows
 * that send one, password reset and phone update, each rolled their own: two
 * code lengths, two cache key conventions, two copies of the "did the SMS
 * send" branch, and **both bypassed the failover chain entirely** by calling
 * `App\Services\SmsService` (Twilio only, no fallback) instead of
 * `MessagingService`. The chain's own docblock says it exists because "an OTP
 * that does not arrive is an account nobody can create", so the one message it
 * was built for was the one message that skipped it.
 *
 * Delivery is delegated to `MessagingService::sendOtp`, which walks
 * WhatsApp then SMS and composes the body in exactly one place.
 */
class OtpService
{
    /**
     * Six digits, everywhere.
     *
     * Password reset used to issue four, a 10,000-wide space. With a ten minute
     * window and the throttle on the verify route that is not immediately
     * exploitable, but it is thin, and there is no reason for two flows in one
     * app to have different strengths. Six digits is 1,000,000.
     */
    private const LENGTH = 6;

    /** Matches the "valable 10 minutes" in the message body. */
    private const TTL_SECONDS = 600;

    /**
     * Wrong guesses before the code dies.
     *
     * The route throttle limits how FAST someone can guess; this limits how
     * MANY times, which is the part that matters against a code that lives ten
     * minutes. Burning the code on the fifth miss means a stolen phone number
     * cannot be ground down inside one window.
     */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private MessagingService $messaging) {}

    /**
     * Issues a code, stores it, and sends it.
     *
     * `$payload` rides along with the code and comes back from `verify()`. The
     * phone-update flow uses it to remember which number was being confirmed,
     * so the pending number is never taken from the verify request, where a
     * caller could swap it for somebody else's.
     *
     * @param  array<string, mixed>  $payload
     * @return array{sent: bool, code: string}
     */
    public function issue(string $purpose, string $key, string $phone, array $payload = []): array
    {
        /*
         * `random_int`, not `rand`.
         *
         * Both previous call sites used `rand()`, which is a Mersenne Twister
         * seeded predictably enough that a sequence of codes can be recovered
         * from a few observed values. This is a credential. It gets the CSPRNG.
         */
        $code = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);

        Cache::put($this->key($purpose, $key), [
            'code' => $code,
            'payload' => $payload,
            'attempts' => 0,
        ], self::TTL_SECONDS);

        $result = $this->messaging->sendOtp($phone, $code);

        if (! $result->ok) {
            // The code is dropped rather than left live: a code nobody received
            // is a code only an attacker benefits from.
            Cache::forget($this->key($purpose, $key));

            Log::warning('OTP could not be delivered', [
                'purpose' => $purpose,
                'channel' => $result->channel,
                'error' => $result->error,
                'phone' => $this->mask($phone),
            ]);
        } else {
            /*
             * The code itself is NEVER logged, in any branch.
             *
             * A previous version of the password reset logged it alongside the
             * phone number, which put every live reset code in plaintext in
             * storage/logs. Anyone who could read the log could take any
             * account. The phone is masked to its last four so the flow stays
             * traceable without the log becoming a directory.
             */
            Log::info('OTP issued', [
                'purpose' => $purpose,
                'channel' => $result->channel,
                'phone' => $this->mask($phone),
            ]);
        }

        return ['sent' => $result->ok, 'code' => $code];
    }

    /**
     * Checks a code and consumes it.
     *
     * Returns the payload given at `issue()` on success, or null on any
     * failure. Null covers expired, never issued, wrong, and out of attempts,
     * deliberately: telling a caller which of those it was tells somebody
     * probing whether a number is registered.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $purpose, string $key, string $code): ?array
    {
        $cacheKey = $this->key($purpose, $key);
        $record = Cache::get($cacheKey);

        if (! is_array($record) || ! isset($record['code'])) {
            return null;
        }

        /*
         * `hash_equals`, not `===`.
         *
         * A plain comparison on a secret returns as soon as two characters
         * differ, so how long it takes leaks how much of the code was right.
         * Over a network that signal is noisy, but it costs nothing to remove.
         */
        if (! hash_equals((string) $record['code'], trim($code))) {
            $attempts = (int) ($record['attempts'] ?? 0) + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                Cache::forget($cacheKey);
            } else {
                /*
                 * Re-put with the REMAINING ttl, not a fresh one.
                 *
                 * Writing the full TTL back on every wrong guess would let
                 * somebody hold a code open indefinitely by guessing slowly.
                 */
                Cache::put($cacheKey, [...$record, 'attempts' => $attempts], self::TTL_SECONDS);
            }

            return null;
        }

        // Single use. A correct code is spent whether or not what follows it
        // succeeds, so a replay cannot ride the same code twice.
        Cache::forget($cacheKey);

        return is_array($record['payload'] ?? null) ? $record['payload'] : [];
    }

    /**
     * Namespaced per purpose.
     *
     * A password-reset code must not satisfy a phone-update check, which two
     * independently chosen key prefixes happened to guarantee before and would
     * not have survived a third flow being added.
     */
    private function key(string $purpose, string $key): string
    {
        return 'otp:' . $purpose . ':' . $key;
    }

    /** Last four only, matching the audit redactor. */
    private function mask(string $phone): string
    {
        return strlen($phone) <= 4
            ? str_repeat('*', strlen($phone))
            : str_repeat('*', strlen($phone) - 4) . substr($phone, -4);
    }
}
