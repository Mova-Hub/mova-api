<?php

namespace App\Providers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Wires the four observability systems into one trail.
 *
 * Mova runs four things that answer four different questions:
 *
 *   · the activity log  — who did what, and can we prove it
 *   · Sentry            — what broke, and on which release
 *   · PostHog           — what people tried and abandoned (client-side)
 *   · Laravel's logs    — what the server did, in order
 *
 * Kept separate on purpose; they have different retention, different
 * audiences and different privacy exposure. What makes them ONE system rather
 * than four dashboards is the shared `request_id` this provider pushes into
 * Sentry's scope — the same uuid already carried by every activity row, every
 * log line, and the `X-Request-Id` response header.
 *
 * Given an error in Sentry you can therefore reach the exact mutations that
 * request performed, and the exact log lines around it, without guessing from
 * timestamps.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(\Sentry\SentrySdk::class) || empty(config('sentry.dsn'))) {
            // No DSN in local development — nothing to configure, and calling
            // into the SDK would be a wasted no-op on every request.
            return;
        }

        configureScope(function (Scope $scope): void {
            $scope->setTag('request_id', ActivityLogger::requestId());

            $user = Auth::user();

            if ($user instanceof User) {
                /*
                 * ID and role only.
                 *
                 * Enough to answer "is this happening to one account or all of
                 * them", and to tell a staff bug from a customer bug. Never the
                 * name, email or phone — `send_default_pii` is off precisely so
                 * that is a deliberate decision made here rather than a default
                 * nobody reviewed.
                 */
                $scope->setUser(['id' => $user->getKey()]);
                $scope->setTag('actor.type', 'staff');
                $scope->setTag('actor.role', (string) $user->role);
            } elseif ($user instanceof Client) {
                $scope->setUser(['id' => $user->getKey()]);
                $scope->setTag('actor.type', 'client');
            }
        });
    }

    /**
     * Last-chance scrub before an event leaves the server.
     *
     * Registered from `config/sentry.php` would be tidier, but the callable has
     * to be a real closure and config files must stay serialisable for
     * `config:cache` — a closure in one silently breaks caching in production.
     *
     * @see bootstrap/app.php, where this is attached
     */
    public static function scrubEvent(Event $event, ?EventHint $hint): ?Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            // Belt and braces over `send_default_pii => false`: a custom
            // integration or a future SDK default could reintroduce these, and
            // a leak here is a leak into a third party's retained store.
            unset($request['cookies'], $request['data'], $request['env']);

            foreach (['authorization', 'cookie', 'x-xsrf-token'] as $header) {
                unset($request['headers'][$header]);
            }

            $event->setRequest($request);
        }

        return $event;
    }
}
