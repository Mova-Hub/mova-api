<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Field\AuthController as FieldAuthController;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the field team — `control/`, and nothing else.
 *
 * A second gate rather than two more entries in `EnsureStaff::STAFF_ROLES`, and
 * the distinction is the whole point of the role split.
 *
 * A controller rides a bus and taps Pass cards. Putting that role in
 * `STAFF_ROLES` would have been one line and would have handed every inspector
 * `GET /clients` (every customer's name, phone and email), `GET /admin/payments`
 * (the ledger), and `POST /reservations/{id}/charge` (the ability to push a
 * payment prompt to a stranger's handset) — because those routes are gated on
 * that constant and on nothing else. Phones get left on buses. The gate has to
 * match the job.
 *
 * The three checks are the same three `EnsureStaff` makes, for the same reasons:
 *
 *  1. **The token belongs to a `User`.** An instanceof check, not a role check.
 *     `Client` also uses `HasApiTokens` and has no `role` column at all, so a
 *     role comparison alone reads `null` — which is not equal to any allowed
 *     value today, and is exactly the kind of accident that starts passing when
 *     somebody later writes `!== 'x'`.
 *  2. **The role is a field or staff role.** Staff pass deliberately: an admin
 *     needs to open the field app to reproduce what an inspector is reporting.
 *     Fleet roles (`driver`, `conductor`, `owner`) do not — they are records of
 *     people, not accounts.
 *  3. **The account is active.** A token issued before somebody was suspended
 *     keeps working until it is revoked; without this, suspending an inspector
 *     does nothing to the shift already running on their phone.
 *
 * Passing this gate is not authorisation for a particular mission. Every
 * mission route scopes to `coordinator_id`, because an id in a URL is a claim,
 * never a permission.
 */
class EnsureField
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! in_array($user->role, [...User::FIELD_ROLES, ...User::STAFF_ROLES], true)
            || $user->status !== 'active'
            /*
             * Fourth check: the TOKEN is a field token, not merely the user a
             * field user.
             *
             * An admin holds a back-office token in `manager/` and a field token
             * in `control/`, and the two must not be interchangeable. The phone
             * is the thing that gets left on a bus, so its credential must not
             * reach the clients list even though the person's role would allow
             * it. Tokens issued before abilities existed hold `*` and `can()`
             * returns true for those, so no live session breaks on deploy.
             */
            || ! $user->currentAccessToken()?->can(FieldAuthController::ABILITY)
        ) {
            // One message for all four failures. Telling a caller which check
            // they failed confirms whether an account exists, what role it
            // holds, and whether it is suspended.
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return $next($request);
    }
}
