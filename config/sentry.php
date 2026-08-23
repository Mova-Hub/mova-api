<?php

return [
    /*
     * Empty DSN disables Sentry entirely.
     *
     * That is the correct default for local development: nobody wants their
     * own typos landing in the team's error feed, and an unset DSN is a
     * no-op rather than a startup failure.
     */
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
     * PII OFF.
     *
     * With this on, Sentry attaches request bodies, cookies and the
     * authenticated user's full record to every event. This API handles phone
     * numbers, home addresses, Mobile Money numbers and password-reset flows —
     * an error report is not a place any of that should end up, and once it is
     * in a third party's store it is not straightforwardly deletable.
     *
     * The user is still identified, but only by id and role — see the
     * `before_send` hook below. That is enough to answer "is this happening to
     * one account or all of them" without shipping the account itself.
     */
    'send_default_pii' => false,

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        // Bindings would carry the values being queried — phone numbers,
        // tokens, OTP hashes. The statement alone is enough to locate a bug.
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'sql_queries' => true,
        'sql_origin' => true,
        'views' => false,
        'missing_routes' => false,
        // A 404 from a bot scanning for /wp-admin is not an error worth a seat
        // in the feed.
        'default_integrations' => true,
    ],

    /*
     * 20% of transactions.
     *
     * Performance data is sampled because it is billed per transaction and
     * because a fifth of the traffic is more than enough to see that an
     * endpoint has become slow. ERRORS are never sampled — every one is sent.
     */
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),

    /*
     * Errors that are not bugs.
     *
     * A validation failure, a 404, a refused login and an expired token are all
     * the application working correctly. Reporting them buries the real
     * exceptions under noise, which is how teams learn to ignore the feed.
     */
    'ignore_exceptions' => [
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
    ],

    'ignore_transactions' => [
        '/up', // Laravel's health check, hit constantly by the platform.
    ],
];
