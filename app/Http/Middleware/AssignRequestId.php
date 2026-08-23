<?php

namespace App\Http\Middleware;

use App\Domain\Audit\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request one id, shared by everything that records it.
 *
 * This is the join key that turns four separate systems into one trail: the
 * activity log row, the Laravel log lines, the Sentry event, and the HTTP
 * response header all carry the same uuid. Without it, "an order was changed
 * wrongly at 14:32" means reading three dashboards and hoping the timestamps
 * line up.
 *
 * An inbound `X-Request-Id` is honoured so a value set at the load balancer or
 * by the mobile client survives — but only if it looks like a uuid, because it
 * is echoed back in a header and written into a log, and an unvalidated
 * client-supplied string in both is how log injection happens.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');

        $id = is_string($incoming) && Str::isUuid($incoming)
            ? $incoming
            : (string) Str::uuid();

        ActivityLogger::setRequestId($id);

        // Every log line for the rest of this request carries it, with no
        // caller having to remember to pass it.
        Log::withContext(['request_id' => $id]);

        $response = $next($request);

        // Echoed so a client — or a user pasting a screenshot into support —
        // can quote the exact request.
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
