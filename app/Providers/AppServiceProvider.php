<?php

namespace App\Providers;

use App\Domain\Pricing\Services\PricingEngine;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

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
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
        // OPTION 1: Deep Link (Opens app directly if installed)
        // return "myapp://reset-password?token={$token}&email={$notifiable->getEmailForPasswordReset()}";

        // OPTION 2: Web Landing Page (Recommended)
        // This page should strictly handle the deep linking logic or host the reset form.
        return config('app.frontend_url') . "/password-reset?token={$token}&email={$notifiable->getEmailForPasswordReset()}";
    });
    }
}
