<?php

namespace App\Domain\Payment\Support;

/**
 * Getting a payer's number into E.164, whatever shape it arrived in.
 *
 * The payment endpoints validate `phone` as `/^\+[1-9]\d{7,14}$/`, and a client
 * that sends anything else gets a 422 quoting the format it failed to produce.
 * That is a needlessly brittle contract for a value people type: `06 407 4926`,
 * `+242 06 407 4926` and `00242064074926` are the same number, and rejecting
 * two of the three tells someone their own phone number is wrong.
 *
 * So the shape is normalised BEFORE validation rather than demanded of every
 * caller. The regex stays exactly as strict — it just now judges a string that
 * has had its spaces, dots and dashes removed and its country code restored.
 *
 * **Not the same as `BaseDriver::msisdn()`, and deliberately its inverse.**
 * MTN and Airtel want a national MSISDN with no country code (`064074926`);
 * this produces the E.164 the API stores and displays. The driver strips what
 * this adds, which is correct: one shape for our records, another for theirs.
 */
final class PhoneNumber
{
    /**
     * Congo-Brazzaville. The only market Mova operates in, and the assumption
     * is applied ONLY when the caller supplied no country code at all — an
     * explicit `+` or `00` prefix always wins.
     */
    private const DEFAULT_DIAL_CODE = '242';

    /** How many digits a Congolese national number has, e.g. `064074926`. */
    private const NATIONAL_LENGTH = 9;

    /**
     * Returns E.164 (`+242064074926`), or null when there is nothing usable.
     *
     * Null rather than a guess: a half-typed number must fail validation as a
     * missing field, not be padded into a different subscriber's line.
     */
    public static function toE164(?string $raw, string $defaultDialCode = self::DEFAULT_DIAL_CODE): ?string
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            return null;
        }

        // Everything that is not a digit goes: spaces, dots, dashes, brackets,
        // the non-breaking space that phones and spreadsheets like to insert.
        $digits = preg_replace('/\D/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            // Already carried a country code; the caller has been explicit.
            return '+' . $digits;
        }

        if (str_starts_with($digits, '00')) {
            // The international prefix used across francophone Africa.
            return '+' . substr($digits, 2);
        }

        if (str_starts_with($digits, $defaultDialCode)
            && strlen($digits) === strlen($defaultDialCode) + self::NATIONAL_LENGTH) {
            // E.164 with the `+` lost somewhere in transit — a very common
            // shape when a number has been through a spreadsheet or a form.
            return '+' . $digits;
        }

        if (strlen($digits) === self::NATIONAL_LENGTH) {
            // A bare national number, which is what people actually type and
            // what the app's own phone field displays.
            return '+' . $defaultDialCode . $digits;
        }

        /*
         * Anything else is returned with a leading `+` and left to the
         * validator. Guessing at a length we do not recognise would be worse
         * than a clear "numéro invalide" — this is money.
         */
        return '+' . $digits;
    }
}
