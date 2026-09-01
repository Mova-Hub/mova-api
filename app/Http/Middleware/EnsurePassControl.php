<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Mova Pass fare-control surface: the blacklist, the snapshot, the signing
 * keys, and the bulk scan upload.
 *
 * These four endpoints used to sit inside the `staff` group, which admits
 * `admin` and `agent` and nobody else. That is the back-office, and it does not
 * include the one role whose entire job this is. A contrôleur could sign in to
 * Control, reach the sync screen, press "Télécharger les données" and get 403,
 * which is exactly what happened.
 *
 * Deliberately NOT `field`. That gate admits coordinators too, and a coordinator
 * runs chartered trips: they have no reason to hold a copy of every subscriber's
 * card identifier. `field` answers "may this person use the app at all", and
 * widening it to fix a 403 is how a gate stops meaning anything.
 *
 * So: the role that inspects cards, plus the back-office that has the data
 * anyway. Nothing else.
 */
class EnsurePassControl
{
    /**
     * Controllers do the job; staff already own the data through the
     * back-office. Coordinators are absent on purpose.
     */
    private const ROLES = ['controller', ...User::STAFF_ROLES];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! in_array($user->role, self::ROLES, true)
            || $user->status !== 'active'
        ) {
            // One message for all three failures, matching EnsureField and
            // EnsureStaff. Saying which check failed confirms whether an account
            // exists, what role it holds, and whether it is suspended.
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return $next($request);
    }
}
