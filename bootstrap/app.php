<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
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
        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureStaff::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
