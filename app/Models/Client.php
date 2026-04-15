<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\CanResetPassword;
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
        'avatar'
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


    public function orders()
    {
        return $this->hasMany(Order::class);
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

}
