<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\CanResetPassword;

class Client extends Authenticatable implements CanResetPassword
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
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
        return $this->fcmTokens()->pluck('fcm_token')->toArray();
    }

    // Assuming your relationship is defined like this (if not, add it)
    public function fcmTokens()
    {
        return $this->hasMany(ClientFcmToken::class);
    }
}
