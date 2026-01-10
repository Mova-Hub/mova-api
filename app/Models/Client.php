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

    public function fcmTokens()
    {
        return $this->hasMany(ClientFcmToken::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // REQUIRED by laravel-notification-channels/fcm
    public function routeNotificationForFcm()
    {
        // Return array of all active tokens for this user
        return $this->fcmTokens()->pluck('fcm_token')->toArray();
    }
}
