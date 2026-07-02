<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'first_name',
        'email',
        'phone',
        'avatar_url',
        'license_no',
        'permit_expiration_date',
        'address',          // JSON: {street, quartier, arrondissement, city, department, country}
        'password',
        'role',             // driver|owner|conductor|agent|admin
        'status',           // active|inactive|suspended
        'email_verified_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'phone_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'address'                 => 'array',
            'permit_expiration_date'  => 'date',
        ];
    }

    public function getAvatarUrlAttribute($value)
    {
        if (!$value) return null;
        // Already absolute URL (e.g. uploaded externally)
        if (str_starts_with($value, 'http')) return $value;
        return asset('storage/' . $value);
    }

    /* ─── Relationships ─────────────────────────────────────────────────────── */

    public function busesAsDriver(): HasMany
    {
        return $this->hasMany(Bus::class, 'assigned_driver_id');
    }

    public function busesAsConductor(): HasMany
    {
        return $this->hasMany(Bus::class, 'assigned_conductor_id');
    }

    public function ownedBuses(): HasMany
    {
        return $this->hasMany(Bus::class, 'operator_id');
    }
}
