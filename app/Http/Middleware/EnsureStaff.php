<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AuthController;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to active back-office staff.
 *
 * This exists because `auth:sanctum` alone does NOT mean "a staff member".
 * `App\Models\Client` also uses `HasApiTokens`, and Sanctum resolves whichever
 * model owns the presented token — so before this middleware, every customer's
 * mobile token authenticated successfully against the entire back-office API:
 * `GET /clients` (every customer's name, phone and email), `GET /reservations`,
 * `GET /staff`, and `POST /quote`, which returns Mova's commission and the
 * operator payout.
 *
 * Three things are checked, and all three matter:
 *
 *  1. **The token belongs to a `User`.** An instanceof check, not a role check —
 *     roles are strings and `Client` has no `role` column at all, so comparing
 *     one would read `null` and could be made to pass by accident later.
 *  2. **The role is a back-office role.** `users.role` also covers `driver`,
 *     `conductor` and `owner`, who are fleet records rather than operators.
 *  3. **The account is active.** A token issued before someone was suspended
 *     keeps working until it is revoked; without this, suspending an account
 *     does nothing to a session already in progress.
 */
class EnsureStaff
{
    /** Roles that may reach the back-office at all. */
    private const STAFF_ROLES = ['admin', 'agent'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! in_array($user->role, self::STAFF_ROLES, true)
            || $user->status !== 'active'
            /*
             * The token has to be a back-office token.
             *
             * The mirror of the check in `EnsureField`. An admin's `control/`
             * handset holds a token scoped to `field`, and that token must not
             * open the clients list or the payments ledger just because the
             * person behind it is an admin. Tokens issued before abilities
             * existed hold `*` and pass, so no live session breaks on deploy.
             */
            || ! $user->currentAccessToken()?->can(AuthController::ABILITY)
        ) {
            // One message for all three failures. Telling a caller which check
            // they failed confirms whether an account exists, what role it
            // holds, and whether it is suspended.
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return $next($request);
    }
}
