<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A payment method, configured by ops rather than by a deploy.
 *
 * Half of the "add a provider without shipping code" promise; the other half is
 * a class implementing PaymentDriver, named by `driver`.
 *
 * @see MOVA-WALLET-AND-PAYMENTS.md §5.4
 */
class PaymentProvider extends Model
{
    protected $fillable = [
        'code', 'driver', 'label', 'description', 'logo_path', 'brand_color',
        'enabled', 'mode', 'credentials',
        'fee_percent', 'fee_fixed', 'fee_bearer', 'min_amount', 'max_amount',
        'currencies', 'countries', 'phone_prefixes', 'fields', 'capabilities',
        'sort_order', 'last_checked_at', 'last_check_status',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        /*
         * Encrypted at rest by Laravel's cast — the column holds ciphertext,
         * so a database dump, a read replica or a backup restored on a laptop
         * carries no usable API keys.
         *
         * It is still `$hidden` below: encryption protects the disk, not a
         * `->toJson()` that someone adds to a resource six months from now.
         */
        'credentials' => 'encrypted:array',
        'fee_percent' => 'float',
        'fee_fixed' => 'integer',
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'currencies' => 'array',
        'countries' => 'array',
        'phone_prefixes' => 'array',
        'fields' => 'array',
        'capabilities' => 'array',
        'sort_order' => 'integer',
        'last_checked_at' => 'datetime',
    ];

    /** Never serialised. See the `credentials` cast comment. */
    protected $hidden = ['credentials'];

    /**
     * Payments made through this provider.
     *
     * Joined on `code`, not on `id` — `payments.provider_code` is a stable
     * string so that historical payments survive a provider row being
     * recreated, which an integer foreign key would not.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'provider_code', 'code');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    /**
     * Whether this provider may take a payment of this size in this currency.
     *
     * Filtering server-side rather than showing everything and failing on tap:
     * a method that appears and then refuses the amount reads as a broken app,
     * not as a limit.
     */
    public function accepts(int $amount, string $currency = 'XAF'): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $currencies = $this->currencies ?: ['XAF'];
        if (! in_array($currency, $currencies, true)) {
            return false;
        }

        if ($amount < $this->min_amount) {
            return false;
        }

        return $this->max_amount === null || $amount <= $this->max_amount;
    }

    /** Provider fee on an amount, in whole francs, rounded up. */
    public function feeOn(int $amount): int
    {
        return (int) ceil($amount * $this->fee_percent) + $this->fee_fixed;
    }

    /**
     * What the client is actually charged.
     *
     * When the fee is borne by the merchant the client pays the face amount and
     * Mova absorbs the cut; when it is borne by the client the fee is added on
     * top. Either way the client sees the number before tapping — a surcharge
     * discovered on the operator's confirmation SMS is a chargeback.
     */
    public function totalFor(int $amount): int
    {
        return $this->fee_bearer === 'client' ? $amount + $this->feeOn($amount) : $amount;
    }

    /** Absolute URL, so the app can render it without knowing the host. */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /**
     * Whether credentials have been entered, without revealing them.
     *
     * This is what read endpoints return in place of the values.
     */
    public function hasCredentials(): bool
    {
        return ! empty(array_filter($this->credentials ?? []));
    }

    /**
     * Last four characters of each credential, for "is this the right key?".
     *
     * Enough to tell two pasted keys apart, useless to anyone who intercepts
     * the response.
     *
     * @return array<string, string>
     */
    public function maskedCredentials(): array
    {
        $out = [];

        foreach ($this->credentials ?? [] as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $out[$key] = str_repeat('•', 8) . substr($value, -4);
        }

        return $out;
    }
}
