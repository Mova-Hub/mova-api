<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * Broadcast auth on the API guard, not on `web`.
     *
     * `withRouting(channels: ...)` — which is where this file pointed — registers
     * /broadcasting/auth with the `web` middleware and its session cookie. No
     * client in this system has one: the passenger app, manager and control all
     * present a Sanctum bearer token, so every private-channel subscription
     * would have been rejected as unauthenticated with nothing in the logs to
     * say why.
     *
     * `auth:sanctum` resolves either a Client or a User; routes/channels.php
     * branches on which, and must.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        /*
         * A MAP, not a list.
         *
         * This was `['admin', EnsureUserIsAdmin::class]` — an array literal, so
         * Laravel registered two aliases named `0` and `1`, and `->middleware('admin')`
         * would have thrown "Target class [admin] does not exist". Nothing ever
         * called it, which is why the mistake survived: every back-office route
         * was running on `auth:sanctum` alone.
         */
        /*
         * Runs on every request, before anything else that logs.
         *
         * `prepend` matters: the request id has to exist before authentication,
         * validation or an exception handler produces its first log line, or
         * those lines are orphaned from the trail they belong to.
         */
        $middleware->prepend(\App\Http\Middleware\AssignRequestId::class);

        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureStaff::class,
            /*
             * The field app's gate, and deliberately NOT `staff`.
             *
             * A bus controller needs a token; they do not need the clients list
             * or the payments ledger. Widening `staff` would have been one line
             * and would have given them both. See EnsureField.
             */
            'field' => \App\Http\Middleware\EnsureField::class,
            /*
             * Narrower than `field`, and it has to be.
             *
             * The Pass fare-control endpoints hand over every subscriber's card
             * identifier. A contrôleur needs that on a bus; a coordinator
             * running charters does not, and `field` admits both. See
             * EnsurePassControl.
             */
            'pass.control' => \App\Http\Middleware\EnsurePassControl::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            // Usage: ->middleware('audit.read:client')
            'audit.read' => \App\Http\Middleware\RecordSensitiveAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Report unhandled exceptions to Sentry.
         *
         * `Integration::handles()` respects `ignore_exceptions` in
         * config/sentry.php, so validation failures, 404s and refused logins
         * stay out of the feed — they are the application working correctly,
         * and burying real exceptions under them is how a team learns to
         * ignore the alerts.
         */
        if (class_exists(\Sentry\Laravel\Integration::class)) {
            \Sentry\Laravel\Integration::handles($exceptions);
        }

        /*
         * A 500 tells the caller its request id.
         *
         * "It failed around 2pm" is otherwise the whole bug report. With the id
         * in the body, a screenshot from an agent is enough to find the exact
         * Sentry event, the exact log lines, and the exact rows in
         * `activity_logs` — the header alone is invisible to anyone not looking
         * at devtools.
         *
         * Only for genuine faults. A 422 already carries the field errors that
         * explain it, and adding a support code to "ce champ est requis" makes
         * an ordinary correction look like an outage.
         */
        $exceptions->respond(function (
            \Symfony\Component\HttpFoundation\Response $response,
            \Throwable $e,
            \Illuminate\Http\Request $request,
        ) {
            if (
                $response->getStatusCode() < 500
                || ! $request->expectsJson()
                || ! $response instanceof \Illuminate\Http\JsonResponse
            ) {
                return $response;
            }

            return $response->setData(
                ((array) $response->getData(true)) + [
                    'request_id' => \App\Domain\Audit\Services\ActivityLogger::requestId(),
                ]
            );
        });
    })->create();
