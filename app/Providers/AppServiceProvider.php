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
        // Paginated collections keep their own "data" array (from the paginator).
        JsonResource::withoutWrapping();

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
        // OPTION 1: Deep Link (Opens app directly if installed)
        // return "myapp://reset-password?token={$token}&email={$notifiable->getEmailForPasswordReset()}";

        // OPTION 2: Web Landing Page (Recommended)
        // This page should strictly handle the deep linking logic or host the reset form.
        return config('app.frontend_url') . "/password-reset?token={$token}&email={$notifiable->getEmailForPasswordReset()}";
    });
    }
}
