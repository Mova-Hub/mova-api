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
        'role',             // driver|owner|conductor|agent|admin|coordinator|controller
        'status',           // active|inactive|suspended
        'is_2fa_enabled',
        'last_login_at',
        'email_verified_at',
        'phone_verified_at',
    ];

    /**
     * Back-office roles — the console, the ledger, the settings.
     *
     * **This list must not grow.** `EnsureStaff` gates every back-office route on
     * it, so adding a role here silently hands that role the clients list, the
     * payments ledger and the payment-provider credentials. Field roles get
     * their own, narrower gate below.
     */
    public const STAFF_ROLES = ['admin', 'agent'];

    /**
     * Field roles — they log in, but only to `control/`.
     *
     * A coordinator owns one reservation end to end; a controller checks Pass
     * subscriptions on a bus. Both need a token and neither has any business in
     * the back-office, which is exactly why they are not in `STAFF_ROLES`. See
     * `EnsureField`.
     */
    public const FIELD_ROLES = ['coordinator', 'controller'];

    /**
     * Everyone who can authenticate at all.
     *
     * The set the back-office may CREATE and MANAGE — which is broader than the
     * set that may reach the back-office. `StaffController` uses this; the
     * middleware uses the two lists above.
     */
    public const LOGIN_ROLES = ['admin', 'agent', 'coordinator', 'controller'];

    /** Fleet people — they appear in the system, they do not log into it. */
    public const FLEET_ROLES = ['driver', 'conductor', 'owner'];

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
            'is_2fa_enabled'          => 'boolean',
            'last_login_at'           => 'datetime',
        ];
    }

    /** An active back-office account. Mirrors the `staff` middleware exactly. */
    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true) && $this->status === 'active';
    }

    /**
     * May this account use `control/`?
     *
     * Staff pass too — an admin has to be able to open the field app to
     * reproduce what an inspector is reporting. Mirrors `EnsureField` exactly,
     * so the two cannot drift.
     */
    public function isField(): bool
    {
        return in_array($this->role, [...self::FIELD_ROLES, ...self::STAFF_ROLES], true)
            && $this->status === 'active';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' && $this->status === 'active';
    }

    public function getAvatarUrlAttribute($value)
    {
        if (!$value) return null;
        // Already absolute URL (e.g. uploaded externally)
        if (str_starts_with($value, 'http')) return $value;
        return asset('storage/' . $value);
    }

    /* ─── Push notifications ────────────────────────────────────────────────── */

    /**
     * Devices this account can be reached on.
     *
     * The staff mirror of `Client::fcmTokens()`. Until this existed, a
     * coordinator handed a convoy could only be told by e-mail — see the
     * `user_fcm_tokens` migration.
     */
    public function fcmTokens(): HasMany
    {
        return $this->hasMany(UserFcmToken::class);
    }

    /** Read by `FcmChannel` via Laravel's `routeNotificationFor{Channel}` convention. */
    public function routeNotificationForFcm(): array
    {
        return $this->fcmTokens()->where('type', 'fcm')->pluck('fcm_token')->toArray();
    }

    /** Read by `ExpoChannel`. Same table, different token format. */
    public function routeNotificationForExpo(): array
    {
        return $this->fcmTokens()->where('type', 'expo')->pluck('fcm_token')->toArray();
    }

    /* ─── Relationships ─────────────────────────────────────────────────────── */

    /** Reservations this user is accountable for delivering. */
    public function coordinatedReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'coordinator_id');
    }

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
