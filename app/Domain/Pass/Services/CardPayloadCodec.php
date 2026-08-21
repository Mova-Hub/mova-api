<?php

namespace App\Domain\Pass\Services;

use App\Domain\Pass\DTOs\Entitlement;

/**
 * The bytes that go on the chip, and back off it.
 *
 *     https://mova.cg/p#<version>.<keyId>.<subscriberId>.<expiry>.<signature>
 *
 * One NDEF URI record, because NDEF abbreviates a well-known scheme to a single
 * byte and because an ordinary phone tapping a lost card then opens a page that
 * says where to return it. Everything after `#` is a fragment, so that
 * incidental tap never sends the entitlement anywhere.
 *
 * This class is the server's half of a format the app and Mova Control also
 * implement (mobile/src/features/pass/card-payload.ts). **All three must agree
 * byte for byte** — the signature is over exactly this string minus its last
 * field, so a difference of one character is a card nobody can verify.
 */
class CardPayloadCodec
{
    /** Big-endian base64url digits, most significant first. */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    /** Full card URI for an entitlement and its signature. */
    public function encode(Entitlement $entitlement, string $signature): string
    {
        $base = rtrim((string) config('pass.payload.base_url', 'https://mova.cg/p'), '#');

        return $base . '#' . implode('.', [
            $entitlement->version,
            $entitlement->keyId,
            $entitlement->subscriberId,
            $this->encodeInt($entitlement->expiryDays),
            $signature,
        ]);
    }

    /**
     * Parses a card URI.
     *
     * Returns null for anything that is not a Mova payload of a version we
     * support, rather than throwing: people tap hotel keys, transport cards and
     * their own bank card at these readers, and "not a Mova card" is a normal
     * outcome, not an exception.
     *
     * **This does not verify anything.** Decoding tells you what the card
     * claims; EntitlementSigner tells you whether to believe it. Keeping the
     * two apart is what stops a caller accidentally trusting a parse.
     *
     * @return array{entitlement: Entitlement, signature: string}|null
     */
    public function decode(string $uri): ?array
    {
        $hash = strpos($uri, '#');
        if ($hash === false) {
            return null;
        }

        $parts = explode('.', substr($uri, $hash + 1));
        if (count($parts) !== 5) {
            return null;
        }

        [$version, $keyId, $subscriberId, $expiry, $signature] = $parts;

        if ($version === '' || $keyId === '' || $subscriberId === '' || $signature === '') {
            return null;
        }

        $supported = (array) config('pass.payload.supported_versions', ['1']);
        if (! in_array($version, $supported, true)) {
            // A layout we do not know is refused, never guessed at: field order
            // could have changed, and misreading an expiry is a free ride.
            return null;
        }

        $days = $this->decodeInt($expiry);
        if ($days === null) {
            return null;
        }

        return [
            'entitlement' => new Entitlement($version, $keyId, $subscriberId, $days),
            'signature' => $signature,
        ];
    }

    /**
     * A client's UUID as the card's 22-character subscriber id.
     *
     * 16 raw bytes → base64url, NOT the 36-character hyphenated string. That is
     * a 14-byte saving on a payload budgeted at ~140, which is the difference
     * between fitting an NTAG213 and not.
     */
    public function subscriberIdFor(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);
        $raw = @hex2bin($hex);

        if ($raw === false || strlen($raw) !== 16) {
            // Not a UUID — fall back to hashing it to 16 bytes so the format
            // stays fixed-width rather than silently producing a short id.
            $raw = substr(hash('sha256', $uuid, true), 0, 16);
        }

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Inverse of subscriberIdFor for real UUIDs. Null if it round-trips wrong. */
    public function uuidFromSubscriberId(string $subscriberId): ?string
    {
        $raw = base64_decode(strtr($subscriberId, '-_', '+/'), true);

        if ($raw === false || strlen($raw) !== 16) {
            return null;
        }

        $hex = bin2hex($raw);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public function encodeInt(int $value): string
    {
        if ($value <= 0) {
            return self::ALPHABET[0];
        }

        $out = '';
        while ($value > 0) {
            $out = self::ALPHABET[$value % 64] . $out;
            $value = intdiv($value, 64);
        }

        return $out;
    }

    public function decodeInt(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $result = 0;
        foreach (str_split($value) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return null;
            }
            $result = $result * 64 + $index;
        }

        return $result;
    }
}
