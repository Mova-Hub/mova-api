<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * The ability every back-office token carries.
     *
     * Named rather than `*`, so a token minted for `manager/` cannot be used
     * against the field surface and vice versa. The pairing lives in
     * `EnsureStaff` and `EnsureField`.
     *
     * Backwards compatible: every token issued before this change holds `*`,
     * and Sanctum's `tokenCan` returns true for it, so nobody is signed out by
     * the deploy.
     */
    public const ABILITY = 'back-office';

    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:80'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken('api-token', [self::ABILITY])->plainTextToken;

        return response()->json([
            'user'       => $this->formatUser($user),
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device'   => ['nullable', 'string', 'max:60'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 422);
        }

        /*
         * Status is checked HERE, not only in middleware.
         *
         * Middleware stops a suspended account using an existing token; without
         * this, suspending someone still lets them log in and mint a fresh one,
         * so the suspension never actually takes effect.
         *
         * Deliberately worded differently from "identifiants incorrects": the
         * password was right, and telling someone their account is suspended is
         * information they need — unlike telling an attacker which half of a
         * failed login was wrong.
         */
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Ce compte est désactivé. Contactez un administrateur.',
            ], 403);
        }

        // Fleet roles (driver, conductor, owner) are records in the system, not
        // operators of it. They have no back-office to log into.
        if (! in_array($user->role, ['admin', 'agent'], true)) {
            return response()->json([
                'message' => 'Ce compte n’a pas accès au back-office.',
            ], 403);
        }

        $deviceName = $credentials['device'] ?? 'web';
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, [self::ABILITY])->plainTextToken;

        // `forceFill`+`saveQuietly`: a sign-in is not a change to the account,
        // and once the activity observer lands (Phase 2) a normal save here
        // would file an "admin updated their own record" entry on every login.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'user'       => $this->formatUser($user),
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($this->formatUser($request->user()));
    }

    public function updateMe(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['sometimes', 'required', 'string', 'max:80'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user->update($data);

        return response()->json($this->formatUser($user->fresh()));
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', Password::min(8), 'different:current_password'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Mot de passe actuel incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($data['new_password'])]);

        // Revoke all other tokens so other sessions are invalidated
        $user->tokens()
            ->where('id', '!=', $request->user()->currentAccessToken()->id)
            ->delete();

        return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }

    public function toggleTwoFA(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        /*
         * The `array_key_exists` guard that used to wrap this is gone.
         *
         * `users` had no `is_2fa_enabled` column, so the guard was permanently
         * false: the endpoint accepted the request, threw it away, and returned
         * `two_fa_enabled: false` regardless — while the back-office rendered a
         * working-looking toggle. A defensive check that silently turns a
         * feature off is worse than no feature.
         *
         * The column now exists (2026_08_23_000001), so this simply writes.
         */
        $user->update(['is_2fa_enabled' => $data['enabled']]);

        return response()->json([
            'two_fa_enabled' => (bool) $user->fresh()->is_2fa_enabled,
        ]);
    }

    // POST /api/auth/verify-password  { password: "..." }
    public function verifyPassword(Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $valid = Hash::check($data['password'], $request->user()->password);
        return response()->json(['valid' => $valid], $valid ? 200 : 422);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $user->phone ?? null,
            'role'           => $user->role ?? null,
            'status'         => $user->status ?? null,
            'two_fa_enabled' => (bool) ($user->is_2fa_enabled ?? false),
            'created_at'     => $user->created_at?->toIso8601String(),
        ];
    }
}
