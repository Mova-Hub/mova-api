<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Signing in to `control/`.
 *
 * This exists because until now **no coordinator and no controller could obtain
 * a token at all.** `POST /api/auth/login` was the only login endpoint in the
 * system and it ends with a hard check for `admin` or `agent`, so every field
 * account got 403 "Ce compte n'a pas accès au back-office" and the field app was
 * unreachable by the two roles it was built for. `EnsureField` admitted them,
 * every `/api/field/*` route waited for them, and nothing could issue them a
 * credential.
 *
 * **The back-office endpoint is deliberately left alone.** `manager/` posts to
 * `/api/auth/login`, so widening its role check would have let a coordinator
 * sign in to the web back-office. Two audiences, two doors.
 *
 * Tokens minted here carry the `field` ability and nothing else, so a handset
 * cannot reach the back-office even when the person holding it is an admin. See
 * `EnsureField` and `EnsureStaff`.
 */
class AuthController extends Controller
{
    /**
     * The ability every field token carries.
     *
     * Named rather than `*` on purpose. An admin opening Control on a bus gets a
     * token that cannot read the clients list or the payments ledger, which is
     * the whole point: the phone is the thing that gets left behind, not the
     * account.
     */
    public const ABILITY = 'field';

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device'   => ['nullable', 'string', 'max:60'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        /*
         * One message for "no such account" and "wrong password".
         *
         * Distinguishing them confirms to an attacker which addresses are real.
         * The two cases below are different: they are things the person in front
         * of the phone genuinely needs told.
         */
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 422);
        }

        /*
         * Checked here, not only in middleware.
         *
         * Middleware stops a suspended account using a token it already holds.
         * Without this check, suspending somebody would still let them log in
         * and mint a fresh one, so the suspension would never take effect.
         */
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Ce compte est désactivé. Contactez un administrateur.',
            ], 403);
        }

        /*
         * `isField()` rather than a role list written out again.
         *
         * It is the same predicate `EnsureField` uses, so login and the gate
         * cannot drift apart. Staff pass too: an admin has to be able to open
         * the field app to reproduce what an inspector is reporting.
         */
        if (! $user->isField()) {
            return response()->json([
                'message' => 'Ce compte n’est pas un compte de terrain. Utilisez le back-office.',
            ], 403);
        }

        /*
         * One token per named device, replaced on each sign-in.
         *
         * A field handset is shared between shifts and reinstalled often. Left
         * to accumulate, one phone would hold a dozen live tokens and revoking
         * the right one would be guesswork.
         */
        $deviceName = $credentials['device'] ?? 'mova-control';
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, [self::ABILITY])->plainTextToken;

        // `forceFill` + `saveQuietly`: signing in is not a change to the
        // account, and a normal save would file an "updated their own record"
        // entry in the activity log on every shift.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'user'         => $this->formatUser($user),
            'token'        => $token,
            'token_type'   => 'Bearer',
            'abilities'    => [self::ABILITY],
            'capabilities' => $this->capabilities($user),
        ]);
    }

    /**
     * Who this token belongs to, and what it may do.
     *
     * Called on every cold start with a signal. It is also how a revoked or
     * suspended account stops working on the next launch: anything but 200 and
     * the app drops the session.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user'         => $this->formatUser($user),
            'capabilities' => $this->capabilities($user),
        ]);
    }

    /**
     * Ends this device's session and nothing else.
     *
     * Only the token that made the request is deleted, so signing out on one
     * handset does not sign the same coordinator out of another. That matters
     * for an ops lead carrying two phones.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Session fermée.']);
    }

    /**
     * What the app may show, decided by the server.
     *
     * Control re-derived this from the role locally, which is a second place for
     * the rule to be wrong. Sending it means adding a capability later does not
     * need an app release, and the app's local copy becomes a fallback for a
     * cold start with no signal rather than the source of truth.
     *
     * These mirror `EnsurePassControl` and the `coordinator_id` scoping on the
     * mission endpoints. They are a hint for the UI, never an authorisation:
     * every route is still gated server side.
     */
    private function capabilities(User $user): array
    {
        return [
            // Downloading the Pass blacklist and snapshot, and uploading scans.
            // Coordinators are excluded: those payloads carry every subscriber's
            // card identifier and a charter coordinator has no use for them.
            'pass_control' => in_array($user->role, ['controller', ...User::STAFF_ROLES], true),

            // Being assigned charter missions. A controller's list is empty
            // rather than forbidden, so this only says whether the tab is
            // meaningful.
            'missions' => in_array($user->role, ['coordinator', ...User::STAFF_ROLES], true),
        ];
    }

    /**
     * The field's view of a user.
     *
     * Deliberately narrower than the back-office `formatUser`: no `two_fa_enabled`,
     * no `created_at`. A phone that gets left on a bus should carry the minimum
     * that identifies whose shift it is.
     */
    private function formatUser(User $user): array
    {
        return [
            'id'     => $user->id,
            'name'   => $user->name,
            'email'  => $user->email,
            'phone'  => $user->phone ?? null,
            'role'   => $user->role,
            'status' => $user->status,
        ];
    }
}
