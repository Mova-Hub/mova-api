<?php

namespace App\Providers;

use App\Domain\Payment\PaymentDriverRegistry;
use App\Domain\Pricing\Services\PricingEngine;
use App\Domain\Settings\SettingsRepository;
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

        /*
         * Settings are read many times per request — the payment sheet alone
         * touches half a dozen. A singleton means one query and one in-memory
         * map per request instead of a cache round trip per lookup.
         */
        $this->app->singleton(SettingsRepository::class);

        /*
         * The registry memoises provider rows for the life of the request, so
         * it must be a singleton or the memo is pointless.
         */
        $this->app->singleton(PaymentDriverRegistry::class);
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
            /*
             * Settings and provider credentials are the highest-value targets
             * in the system: changing a fee or an API key silently would be
             * indistinguishable from a compromise. The Redactor strips the
             * secrets themselves, so what lands is "who changed which key,
             * when" — which is exactly the question worth answering.
             */
            \App\Models\Setting::class,
            \App\Models\PaymentProvider::class,
            /*
             * WalletEntry is append-only, so the observer only ever records
             * creations — but a credit granted by hand is money, and it must be
             * as attributable as any other payment.
             */
            \App\Models\WalletEntry::class,
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
