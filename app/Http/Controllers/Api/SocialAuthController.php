<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Services\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Thin HTTP shell. All verification and account-matching rules live in
 * SocialAuthService so they stay testable and in one place.
 */
class SocialAuthController extends Controller
{
    public function __construct(private SocialAuthService $socialAuth)
    {
    }

    /**
     * Issue a single-use nonce for a native Apple sign-in.
     *
     * POST /app/v1/auth/social/nonce
     *
     * The app passes `hashed_nonce` to Apple and returns `nonce_id` with the
     * identity token. Keeping generation here means the device needs no crypto
     * library, and lets the nonce be enforced single-use.
     */
    public function nonce(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data'   => $this->socialAuth->issueNonce(),
        ]);
    }

    /**
     * Sign in (or register) with Google or Apple.
     *
     * POST /app/v1/auth/social
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider'    => ['required', 'string', Rule::in(SocialAuthService::PROVIDERS)],
            'id_token'    => ['required', 'string'],
            // Opaque id from POST /auth/social/nonce. Apple only.
            'nonce_id'    => ['nullable', 'string', 'max:64'],
            // Apple returns the name only on FIRST authorisation, so the client
            // forwards it once. Display only — never used to identify anyone.
            'full_name'   => ['nullable', 'string', 'max:80'],
            'email'       => ['nullable', 'email', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $client = $this->socialAuth->authenticate(
                $data['provider'],
                $data['id_token'],
                $data['nonce_id'] ?? null,
                [
                    'full_name' => $data['full_name'] ?? null,
                    'email'     => $data['email'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            // Never echo the provider's raw error back to the client: it can
            // leak configuration detail. Log it, return something generic.
            report($e);

            return response()->json([
                'status'  => false,
                'message' => 'Connexion impossible. Veuillez réessayer.',
            ], 401);
        }

        $client->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'status'  => true,
            'message' => 'Connexion réussie.',
            'data'    => [
                'user'  => new ClientResource($client),
                'token' => $client->createToken($data['device_name'] ?? 'mobile')->plainTextToken,
            ],
        ]);
    }
}
