<?php

namespace App\Domain\Pass\Services;

use App\Domain\Pass\DTOs\Entitlement;
use App\Domain\Pass\Exceptions\PassException;
use SensitiveParameter;

/**
 * Ed25519 signing for card entitlements.
 *
 * This class is the entire reason offline fare inspection can be trusted, so
 * the constraints are worth stating rather than assuming:
 *
 *  - **Asymmetric, not HMAC.** Mova Control verifies with a key that ships
 *    inside an APK, and an APK is decompilable. With HMAC that same key signs,
 *    so extracting it lets anyone mint an entitlement with any expiry they
 *    like — and offline is precisely where no server check can catch it. A
 *    public key gains an attacker nothing.
 *  - **The private key never leaves this server.** Not to the back-office, not
 *    into a response, not into a log. The counter asks the API for a signature;
 *    it never signs anything itself.
 *  - **Never APP_KEY.** That key already protects sessions and cookies. One key
 *    across unrelated domains means one leak compromises everything and
 *    rotation becomes impossible. A missing dedicated key throws — it does not
 *    quietly fall back.
 *
 * Ed25519 also happens to fit the product: 64-byte signatures (the byte budget
 * in §4.2 is built around that) and sub-millisecond verification on cheap
 * Android hardware, which is what makes the <1s verdict target reachable.
 */
class EntitlementSigner
{
    private const SIGNATURE_BYTES = SODIUM_CRYPTO_SIGN_BYTES;      // 64
    private const PUBLIC_KEY_BYTES = SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES; // 32
    private const SECRET_KEY_BYTES = SODIUM_CRYPTO_SIGN_SECRETKEYBYTES; // 64

    /**
     * The codec owns the field encodings.
     *
     * Injected rather than reimplemented: the signed message must be *exactly*
     * the card fragment minus its signature, so the expiry has to be encoded by
     * the same code that writes it to the chip. An earlier draft packed the
     * expiry here as big-endian bytes while the codec wrote base-64 digits —
     * every signature would have verified against a message no reader could
     * reconstruct, and nothing would have caught it until a card failed on a
     * bus. One implementation, one chance to be wrong.
     */
    public function __construct(private CardPayloadCodec $codec) {}

    /**
     * The id every NEW card is signed with.
     *
     * Rotation works by changing this and leaving the old key in the map:
     * new cards get the new signature, cards already in circulation keep
     * verifying against the key id written on them (PRD §4.1, criterion A4).
     */
    public function activeKeyId(): string
    {
        return (string) config('pass.signing.active_key_id', '1');
    }

    /**
     * Signs an entitlement, returning a base64url signature.
     *
     * @throws PassException when no usable private key is configured
     */
    public function sign(Entitlement $entitlement): string
    {
        $secret = $this->secretKey($entitlement->keyId);

        $signature = sodium_crypto_sign_detached($this->message($entitlement), $secret);
        // Wipe the copy we pulled into userland memory. Cheap, and it shortens
        // the window in which a crash dump could contain the private key.
        sodium_memzero($secret);

        return $this->toBase64Url($signature);
    }

    /**
     * Verifies a base64url signature against an entitlement.
     *
     * Returns false — never throws — for an unknown key id, malformed base64,
     * or the wrong signature length. A caller checking a card must not be able
     * to tell those apart from a bad signature, and must not have to catch
     * anything to reach a verdict.
     */
    public function verify(Entitlement $entitlement, #[SensitiveParameter] string $signature): bool
    {
        $public = $this->publicKey($entitlement->keyId);
        if ($public === null) {
            return false;
        }

        $raw = $this->fromBase64Url($signature);
        if ($raw === null || strlen($raw) !== self::SIGNATURE_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($raw, $this->message($entitlement), $public);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Raw public key for a key id, or null if unknown.
     *
     * Safe to expose: this is what Mova Control ships with, and a public key
     * cannot forge anything.
     */
    public function publicKey(string $keyId): ?string
    {
        $encoded = $this->keys()[$keyId]['public'] ?? null;
        if (! is_string($encoded)) {
            return null;
        }

        $raw = base64_decode($encoded, true);

        return ($raw !== false && strlen($raw) === self::PUBLIC_KEY_BYTES) ? $raw : null;
    }

    /**
     * Every public key, base64, keyed by id — the bootstrap payload for Mova
     * Control. Only ever public halves; see `secretKey()` for the other one.
     *
     * @return array<string, string>
     */
    public function publicKeys(): array
    {
        $out = [];

        foreach (array_keys($this->keys()) as $keyId) {
            $raw = $this->publicKey((string) $keyId);
            if ($raw !== null) {
                $out[(string) $keyId] = base64_encode($raw);
            }
        }

        return $out;
    }

    public function hasActiveKey(): bool
    {
        try {
            $secret = $this->secretKey($this->activeKeyId());
            sodium_memzero($secret);

            return true;
        } catch (PassException) {
            return false;
        }
    }

    /**
     * Exactly the bytes a card carries, minus the signature.
     *
     * This is the security-critical detail of the whole scheme. The signed
     * message must be reconstructible by an offline verifier from nothing but
     * the card, and must be unambiguous — otherwise two different entitlements
     * could produce the same message and a signature would transfer between
     * them.
     *
     * `.` is a safe separator precisely because it is NOT in the base64url
     * alphabet and none of the four fields can contain one, so the split can
     * never be re-parsed a second way.
     */
    private function message(Entitlement $entitlement): string
    {
        return implode('.', [
            $entitlement->version,
            $entitlement->keyId,
            $entitlement->subscriberId,
            $this->codec->encodeInt($entitlement->expiryDays),
        ]);
    }

    /**
     * @throws PassException
     */
    private function secretKey(string $keyId): string
    {
        $encoded = $this->keys()[$keyId]['secret'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            throw PassException::noSigningKey();
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== self::SECRET_KEY_BYTES) {
            throw PassException::noSigningKey();
        }

        return $raw;
    }

    /** @return array<string, array{public?: string, secret?: string}> */
    private function keys(): array
    {
        $keys = config('pass.signing.keys', []);

        return is_array($keys) ? $keys : [];
    }

    private function toBase64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function fromBase64Url(string $value): ?string
    {
        $raw = base64_decode(strtr($value, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
