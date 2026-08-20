<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;

/**
 * Everything involved in turning a provider identity token into a Mova client.
 *
 * Kept out of the controller so the rules below live in one testable place:
 * adding a provider, or changing how accounts are matched, means touching this
 * file only. The controller stays a thin HTTP shell.
 */
class SocialAuthService
{
    /** Providers this service knows how to verify. */
    public const PROVIDERS = ['google', 'apple'];

    /** Long enough to complete a sign-in, short enough to limit a stolen nonce. */
    private const NONCE_TTL_MINUTES = 10;

    /**
     * Issue a single-use nonce for a native Apple sign-in.
     *
     * The RAW nonce never leaves the server. The client receives only its
     * SHA-256 (which is what Apple embeds in the identity token) plus an opaque
     * id to hand back afterwards.
     *
     * This is deliberately server-side rather than generated on the device:
     *   - randomness comes from random_bytes(), not a JS PRNG
     *   - the nonce can be enforced SINGLE-USE, which a client-generated one
     *     cannot be — the server has nothing to compare a replay against
     *   - the app needs no crypto library at all
     *
     * DEPLOYMENT NOTE: this relies on the cache being shared and persistent.
     * The `array` driver loses nonces between requests, and `file` is per-server
     * — behind more than one app server that means a nonce issued by one node
     * cannot be redeemed on another, and Apple sign-in fails intermittently.
     * Use redis/database in production.
     *
     * @return array{nonce_id:string, hashed_nonce:string}
     */
    public function issueNonce(): array
    {
        $raw = bin2hex(random_bytes(32));
        $id  = (string) Str::uuid();

        Cache::put($this->nonceKey($id), $raw, now()->addMinutes(self::NONCE_TTL_MINUTES));

        return [
            'nonce_id'     => $id,
            'hashed_nonce' => hash('sha256', $raw),
        ];
    }

    private function nonceKey(string $id): string
    {
        return "social_nonce:{$id}";
    }

    /**
     * Verify the token with the provider, then find or create the account.
     *
     * @throws RuntimeException when the token fails verification.
     */
    public function authenticate(
        string $provider,
        string $idToken,
        ?string $nonceId = null,
        array $fallback = []
    ): Client {
        $socialUser = $this->verifyToken($provider, $idToken);
        $this->verifyNonce($provider, $idToken, $nonceId);

        return DB::transaction(fn () => $this->resolveClient($provider, $socialUser, $fallback));
    }

    /**
     * Both calls verify the JWT signature against the provider's published keys
     * and check the audience against the configured client id.
     *
     * IMPORTANT: services.google.client_id must be the WEB client id — that is
     * the audience the mobile SDK requests its idToken against on both
     * platforms. Socialite compares against a single value, so a mismatch here
     * rejects every Google sign-in.
     */
    private function verifyToken(string $provider, string $idToken): SocialiteUser
    {
        return match ($provider) {
            'google' => Socialite::driver('google')->stateless()->userFromToken($idToken),
            // The Apple driver exposes a dedicated entry point for the native
            // flow, where we already hold an identity token and there is no
            // authorisation code to exchange.
            'apple'  => Socialite::driver('apple')->userByIdentityToken($idToken),
            default  => throw new RuntimeException("Fournisseur non pris en charge : {$provider}"),
        };
    }

    /**
     * Replay protection.
     *
     * Socialite only accepts a nonce for Facebook; neither its Google driver
     * nor the community Apple driver check one. Without this, a stolen but
     * still-valid identity token is replayable for its whole lifetime — the
     * audience check only blocks tokens minted for a *different* app.
     *
     * Apple hashes the nonce it receives, so the token carries sha256(raw). The
     * client hands back the opaque id from issueNonce(); the raw value is read
     * from the cache here and never travels over the wire.
     *
     * The cache entry is CONSUMED whether or not the comparison succeeds, so a
     * captured token cannot be replayed even with its matching nonce id. This
     * is the part a device-generated nonce cannot provide.
     *
     * Google is exempt: the React Native Google Sign-In SDK offers no way to
     * supply a nonce, so there is nothing to compare. Its mitigations are the
     * audience check plus Google's short token lifetime. Revisit if that SDK
     * ever exposes one.
     */
    private function verifyNonce(string $provider, string $idToken, ?string $nonceId): void
    {
        if ($provider !== 'apple') {
            return;
        }

        if (! $nonceId) {
            throw new RuntimeException('Nonce manquant pour la connexion Apple.');
        }

        $key = $this->nonceKey($nonceId);
        $raw = Cache::pull($key); // single-use: retrieved and deleted together

        if (! $raw) {
            throw new RuntimeException('Nonce inconnu ou expiré.');
        }

        $tokenNonce = $this->decodeClaims($idToken)['nonce'] ?? null;

        if (! $tokenNonce) {
            throw new RuntimeException('Jeton Apple sans nonce.');
        }

        if (! hash_equals(hash('sha256', $raw), $tokenNonce)) {
            throw new RuntimeException('Nonce Apple invalide.');
        }
    }

    /**
     * Reads the JWT payload WITHOUT verifying it — safe only because this runs
     * after verifyToken() has already validated the signature. Never call it
     * before that.
     */
    private function decodeClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new RuntimeException('Jeton mal formé.');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Find the existing account or create one.
     *
     * Matching order matters:
     *   1. provider + provider id — the only fully trustworthy identifier
     *   2. VERIFIED email — links a sign-in to an account they already have
     *
     * An unverified email is never used to match. Doing so would let anyone
     * register a provider account claiming someone else's address and take over
     * their Mova account.
     */
    private function resolveClient(string $provider, SocialiteUser $socialUser, array $fallback): Client
    {
        $providerId = $socialUser->getId();

        $client = Client::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($client) {
            return $client;
        }

        $email = $socialUser->getEmail() ?? ($fallback['email'] ?? null);
        $emailVerified = $this->emailIsVerified($provider, $socialUser);

        if ($email && $emailVerified) {
            $existing = Client::where('email', $email)->first();

            if ($existing) {
                // Attach the provider to the account they already have rather
                // than creating a duplicate.
                $existing->forceFill([
                    'provider'    => $provider,
                    'provider_id' => $providerId,
                ])->save();

                return $existing;
            }
        }

        return Client::create([
            'name'              => $socialUser->getName() ?: ($fallback['full_name'] ?? 'Client Mova'),
            'email'             => $email,
            'email_verified_at' => $emailVerified ? now() : null,
            'provider'          => $provider,
            'provider_id'       => $providerId,
            // No password: this account can only be entered through the
            // provider until the user sets one. A random value keeps the column
            // non-null and unguessable rather than leaving an empty hash.
            'password'          => Str::random(64),
            // Phone is collected later — the app asks for it when a booking
            // actually needs a contact number, rather than blocking sign-up.
            'phone'             => null,
        ]);
    }

    /**
     * Socialite normalises providers into a common User object, which drops the
     * `email_verified` claim, so it is read from the raw payload.
     *
     * Apple only issues an email once the user consents, and it is always
     * verified by Apple, so absence of the claim counts as verified there — but
     * NOT for Google, where an unverified address is possible.
     */
    private function emailIsVerified(string $provider, SocialiteUser $socialUser): bool
    {
        $claim = $socialUser->getRaw()['email_verified'] ?? null;

        if ($claim !== null) {
            return filter_var($claim, FILTER_VALIDATE_BOOLEAN);
        }

        return $provider === 'apple' && ! empty($socialUser->getEmail());
    }
}
