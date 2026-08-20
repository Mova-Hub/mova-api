<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Throwable;

class SocialAuthController extends Controller
{
    /**
     * Sign in (or register) with Google or Apple.
     *
     * POST /app/v1/auth/social
     *
     * Token verification is delegated to Socialite so that signature, issuer
     * and audience checks stay maintained upstream and arrive via composer
     * update. What Socialite does NOT do for these two providers is nonce
     * checking — see verifyNonce() below.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider'    => 'required|string|in:google,apple',
            'id_token'    => 'required|string',
            // Raw (unhashed) nonce the client generated before calling Apple.
            'nonce'       => 'nullable|string|max:120',
            // Apple returns the name only on FIRST authorisation, so the client
            // forwards it once. Display only — never used to identify anyone.
            'full_name'   => 'nullable|string|max:80',
            'email'       => 'nullable|email|max:255',
            'device_name' => 'nullable|string|max:100',
        ]);

        try {
            $socialUser = $this->verifyToken($data['provider'], $data['id_token']);
            $this->verifyNonce($data['provider'], $data['id_token'], $data['nonce'] ?? null);
        } catch (Throwable $e) {
            // Never echo the provider's raw error back to the client: it can
            // leak configuration detail. Log it, return something generic.
            report($e);

            return response()->json([
                'status'  => false,
                'message' => 'Connexion impossible. Veuillez réessayer.',
            ], 401);
        }

        $client = DB::transaction(fn () => $this->resolveClient($data['provider'], $socialUser, $data));

        $client->forceFill(['last_login_at' => now()])->save();

        $token = $client->createToken($data['device_name'] ?? 'mobile')->plainTextToken;

        return response()->json([
            'status'  => true,
            'message' => 'Connexion réussie.',
            'data'    => [
                'user'  => new ClientResource($client),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Both calls verify the JWT signature against the provider's published keys
     * and check the audience against the configured client id.
     *
     * IMPORTANT: services.google.client_id must be the WEB client id, because
     * that is the audience the mobile SDK requests its idToken against on both
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
        };
    }

    /**
     * Replay protection.
     *
     * Socialite only accepts a nonce for Facebook; neither its Google driver
     * nor the community Apple driver check one. Since a stolen-but-valid
     * identity token would otherwise be replayable for its whole lifetime, the
     * check is done here.
     *
     * Apple hashes the nonce it receives, so the token carries sha256(raw) and
     * the client sends us the raw value to compare against.
     *
     * Google is exempt: the React Native Google Sign-In SDK gives no way to
     * supply a nonce, so there is nothing to compare. Its mitigations are the
     * audience check plus Google's short token lifetime. Revisit if that SDK
     * ever exposes one.
     */
    private function verifyNonce(string $provider, string $idToken, ?string $rawNonce): void
    {
        if ($provider !== 'apple') {
            return;
        }

        if (! $rawNonce) {
            throw new RuntimeException('Nonce manquant pour la connexion Apple.');
        }

        $claims = $this->decodeClaims($idToken);
        $tokenNonce = $claims['nonce'] ?? null;

        if (! $tokenNonce) {
            throw new RuntimeException('Jeton Apple sans nonce.');
        }

        if (! hash_equals(hash('sha256', $rawNonce), $tokenNonce)) {
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

        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')) ?: '',
            true
        );

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
    private function resolveClient(string $provider, SocialiteUser $socialUser, array $data): Client
    {
        $providerId = $socialUser->getId();

        $client = Client::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($client) {
            return $client;
        }

        $email = $socialUser->getEmail() ?? $data['email'] ?? null;
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
            'name'              => $socialUser->getName() ?: ($data['full_name'] ?? 'Client Mova'),
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
     * Apple only issues an email at all once the user consents, and it is
     * always verified by Apple, so absence of the claim is treated as verified
     * there but NOT for Google.
     */
    private function emailIsVerified(string $provider, SocialiteUser $socialUser): bool
    {
        $raw = $socialUser->getRaw();

        $claim = $raw['email_verified'] ?? null;

        if ($claim !== null) {
            return filter_var($claim, FILTER_VALIDATE_BOOLEAN);
        }

        return $provider === 'apple' && ! empty($socialUser->getEmail());
    }
}
