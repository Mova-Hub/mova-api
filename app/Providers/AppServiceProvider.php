<?php

namespace App\Providers;

use App\Domain\Pricing\Services\PricingEngine;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PricingEngine::class, fn() => new PricingEngine());

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Apple is not a first-party Socialite driver — SocialiteProviders adds
        // it through this event rather than a service provider entry.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('apple', \SocialiteProviders\Apple\Provider::class);
        });

        // Remove the {"data":{...}} envelope from single-resource responses.
        //
        // NOTE: this also unwraps RESOURCE COLLECTIONS, which the original
        // comment did not account for — `SomeResource::collection(...)` emits a
        // bare array here, and only a *paginated* collection keeps a `data`
        // key (from the paginator, not the resource). The mobile client handles
        // both shapes via `unwrap`/`unwrapList`; manager/ must do the same.
        JsonResource::withoutWrapping();

        /*
         * Audit trail.
         *
         * Registered per model on purpose. A global observer would start
         * logging every new table by accident, including ones whose contents
         * have no business being duplicated into an append-only store.
         *
         * These cover mutations that go through Eloquent. Bulk endpoints use
         * the query builder and bypass model events entirely — they call
         * ActivityLogger directly. See ActivityObserver's docblock.
         */
        foreach ([
            \App\Models\Order::class,
            \App\Models\Reservation::class,
            \App\Models\Bus::class,
            \App\Models\Client::class,
            \App\Models\User::class,
            \App\Models\PassCard::class,
            \App\Models\PassSubscription::class,
            \App\Models\PassPlan::class,
            \App\Models\Payment::class,
            \App\Models\SavedAddress::class,
        ] as $model) {
            $model::observe(\App\Observers\ActivityObserver::class);
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
        // OPTION 1: Deep Link (Opens app directly if installed)
        // return "myapp://reset-password?token={$token}&email={$notifiable->getEmailForPasswordReset()}";

        // OPTION 2: Web Landing Page (Recommended)
        // This page should strictly handle the deep linking logic or host the reset form.
        return config('app.frontend_url') . "/password-reset?token={$token}&email={$notifiable->getEmailForPasswordReset()}";
    });
    }
}
