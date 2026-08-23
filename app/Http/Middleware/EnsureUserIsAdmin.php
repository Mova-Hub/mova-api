<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows a route to administrators.
 *
 * Stacks ON TOP of `staff`, which has already established that the caller is an
 * active back-office `User` — but the instanceof and status checks are repeated
 * here rather than assumed. Middleware that is only safe in a particular order
 * is a trap for whoever adds the next route.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== 'admin' || $user->status !== 'active') {
            // Same wording as EnsureStaff: the difference between "not staff"
            // and "not an admin" is not something a caller needs told.
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return $next($request);
    }
}
