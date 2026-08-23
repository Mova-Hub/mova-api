<?php

namespace App\Http\Middleware;

use App\Domain\Audit\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a READ of something sensitive.
 *
 * Mutations are covered by the model observer; this exists for the questions an
 * observer can never answer — "who looked up this customer's phone number?",
 * "who downloaded the blacklist?", "who pulled the revenue figures?".
 *
 * Applied to a NAMED SUBSET, not to every GET. Logging all reads would bury one
 * price change under a hundred list views, multiply the table by an order of
 * magnitude, and copy far more personal data into an append-only store — which
 * is the opposite of what an audit trail is for.
 *
 *     Route::get(...)->middleware('audit.read:client');
 *
 * Only successful responses are recorded. A 403 is a refusal, and refusals
 * belong in the security log, not the access trail.
 */
class RecordSensitiveAccess
{
    public function __construct(private ActivityLogger $logger) {}

    public function handle(Request $request, Closure $next, string $subject = 'resource'): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $this->logger->log(
            action: $subject . '.accessed',
            context: [
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                // The route parameters identify WHICH record was read, without
                // storing the response body — which would duplicate the very
                // data the log is meant to police access to.
                'parameters' => $request->route()?->parameters() ?? [],
                'query' => $request->query(),
            ],
        );

        return $response;
    }
}
