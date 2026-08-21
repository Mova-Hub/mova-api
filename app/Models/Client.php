<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Authenticatable implements CanResetPassword
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_2fa_enabled',
        'last_login_at',
        'avatar',
        // Social sign-in (Google / Apple). `provider_id` is the provider's
        // subject claim — never the email, which users can change.
        'provider',
        'provider_id',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_2fa_enabled' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    protected $appends = [
        'avatar_url',
    ];


    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Home / work / school shortcuts, plus any custom places.
     * Cascade-deletes with the client — see the migration.
     */
    public function savedAddresses()
    {
        return $this->hasMany(SavedAddress::class);
    }

    /**
     * Mova Pass — cards, subscriptions and scan history.
     *
     * The same person, not a separate "subscriber" record. PRD §6 modelled
     * subscribers as their own table with their own name and phone; collapsing
     * them into Client means a counter agent cannot create a second, divergent
     * identity for somebody who already has an app account.
     */
    public function passCards()
    {
        return $this->hasMany(PassCard::class);
    }

    public function passSubscriptions()
    {
        return $this->hasMany(PassSubscription::class);
    }

    public function passScans()
    {
        return $this->hasMany(PassScan::class);
    }

    /**
     * The subscription that decides whether this client travels today.
     *
     * Latest expiry wins where several overlap, which is what stacked renewals
     * produce.
     */
    public function activePassSubscription()
    {
        return $this->hasOne(PassSubscription::class)->current();
    }

    /**
     * Get the client's FCM tokens for notifications.
     *
     * @return array
     */

    public function routeNotificationForFcm()
    {
        return $this->fcmTokens()
            ->where('type', 'fcm')
            ->pluck('fcm_token')
            ->toArray();
    }

    public function routeNotificationForExpo()
    {
        return $this->fcmTokens()
            ->where('type', 'expo')
            ->pluck('fcm_token')
            ->toArray();
    }

    // Define the relationship with FCM tokens
    public function fcmTokens()
    {
        return $this->hasMany(ClientFcmToken::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get the full URL for the user's avatar.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar ? asset('storage/' . $this->avatar) : null,
        );
    }

}
