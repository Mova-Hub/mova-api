<?php

namespace App\Domain\Audit\Support;

/**
 * Strips secrets out of anything on its way into the audit log.
 *
 * **This is a hard requirement, not a nicety.** The audit table records the
 * before and after of every mutation in the system, which makes it the single
 * largest concentration of personal data anywhere in the application, and
 * unlike the tables it mirrors, it is append-only and retained for months. A
 * password hash, a reset OTP or a signing key that lands here is not something
 * you can go back and delete from one row.
 *
 * Two behaviours, deliberately different:
 *
 *  - **Secrets are replaced entirely.** There is no version of "part of a
 *    password hash" that is useful, and a partial secret is still a secret.
 *  - **Identifiers are masked, not dropped.** An auditor needs to know WHICH
 *    phone number an agent changed a booking to. Removing it makes the entry
 *    useless; keeping it in full makes the log a phone directory. The last four
 *    digits answer the question.
 */
class Redactor
{
    public const REDACTED = '[redacted]';

    /**
     * Never stored, in any form.
     *
     * Matched as a SUBSTRING of the key, case-insensitively, `password`
     * catches `password_confirmation` and `current_password`, and `token`
     * catches `fcm_token` and `remember_token`, without needing every variant
     * enumerated here as somebody adds columns.
     */
    private const SECRET_KEYS = [
        'password',
        'token',
        'secret',
        'otp',
        'signature',
        'private_key',
        'api_key',
        'authorization',
        /*
         * The whole provider credentials bag, replaced wholesale rather than
         * recursed into.
         *
         * Recursion catches `api_key` and `client_secret` by substring, but NOT
         * `subscription_key`, `api_user` or `target_environment`, MTN's four
         * fields would have gone into the audit log half in plaintext. There is
         * no audit value in the values anyway: "who changed which provider's
         * credentials, when" is the whole question.
         */
        'credentials',
    ];

    /**
     * Free text a person typed. Replaced with a note of its size.
     *
     * Support tickets, trip messages and the subject line of either are text a
     * customer wrote to us, and it routinely carries an amount, a phone number,
     * a description of a photograph of an identity card. The audit table is
     * append-only and retained for months, so a copy landing here is a copy
     * nobody can delete afterwards, and it adds nothing: the message lives in
     * its own table, which no endpoint can edit. "Who replied, when" is the
     * question an audit answers; "what did they say" is not.
     *
     * **Matched EXACTLY, unlike the two lists above.** As a substring, `message`
     * would also catch `error_message`, `message_id` and `messaging_channel`,
     * and redacting those costs the log its debugging value while protecting
     * nothing. Anything genuinely secret is in SECRET_KEYS and is caught before
     * this list is reached.
     */
    private const TEXT_KEYS = [
        'body',
        'message',
        'subject',
        'comment',
    ];

    /** Kept, but only the tail. */
    private const MASKED_KEYS = [
        'phone',
        'payer_phone',
        'contact_phone',
        'printed_serial',
        'chip_uid',
        'provider_reference',
    ];

    /**
     * Recurses through arrays so nested payloads are covered too.
     *
     * `waypoints`, `fleet_requirements` and `metadata` are all JSON columns
     * that can carry anything a caller put in them; walking only the top level
     * would let a secret through inside one of those.
     */
    public function scrub(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $out = [];

        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);

            if ($this->matches($lower, self::SECRET_KEYS)) {
                $out[$key] = self::REDACTED;
                continue;
            }

            /*
             * Before the recursion, on purpose.
             *
             * A `body` that arrives as an array is still the thing this list
             * exists to keep out, and walking into it would put its leaves in
             * the log one key at a time.
             */
            if (in_array($lower, self::TEXT_KEYS, true)) {
                $out[$key] = $this->summarise($value);
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->scrub($value);
                continue;
            }

            if ($this->matches($lower, self::MASKED_KEYS) && is_string($value)) {
                $out[$key] = $this->mask($value);
                continue;
            }

            // Guards against a stray blob, a base64 avatar, an encoded
            // payload, bloating a row that is meant to be a summary.
            $out[$key] = is_string($value) && strlen($value) > 500
                ? substr($value, 0, 500) . '…[tronqué]'
                : $value;
        }

        return $out;
    }

    /** Keeps the last four characters: enough to identify, not to reuse. */
    public function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (strlen($value) <= 4) {
            return str_repeat('*', strlen($value));
        }

        return str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }

    /**
     * Replaces free text with the fact that there was some, and how much.
     *
     * The LENGTH is kept because it is the part with audit value and no privacy
     * cost: "the reply grew from 40 characters to 900" is a real signal when
     * somebody asks what changed, and it says nothing about the content. Null
     * stays null, so "was empty, now has text" is still readable.
     */
    private function summarise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return self::REDACTED;
        }

        return $value === ''
            ? ''
            : self::REDACTED . ' (' . mb_strlen($value) . ' caractères)';
    }

    private function matches(string $key, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
