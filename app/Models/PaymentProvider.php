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
        'currencies', 'countries', 'phone_prefixes', 'fields', 'options', 'capabilities',
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
        'options' => 'array',
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
     * The choices this provider presents, resolved for display.
     *
     * An aggregator like Yabetoo sits in front of both MTN and Airtel, so what
     * the customer taps is an OPTION rather than the provider itself. Every
     * other provider returns an empty list and behaves exactly as it always
     * has: one provider, one choice.
     *
     * Logo paths become URLs here rather than in a Resource, so the mobile
     * sheet and the back office cannot disagree about where an option's logo
     * lives. An option with no logo falls back to the provider's own colour
     * rather than to nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolvedOptions(): array
    {
        return collect($this->options ?: [])
            ->filter(fn ($o) => is_array($o) && ! empty($o['code']))
            ->map(fn (array $o) => [
                'code' => (string) $o['code'],
                'label' => (string) ($o['label'] ?? $o['code']),
                'description' => $o['description'] ?? null,
                'logo_url' => ! empty($o['logo_path'])
                    ? Storage::disk('public')->url($o['logo_path'])
                    : null,
                'color' => $o['brand_color'] ?? $this->brand_color,
                // Advisory, exactly as on the provider itself: numbers get
                // ported between operators, so a mismatch warns and never
                // blocks.
                'phone_prefixes' => $o['phone_prefixes'] ?? [],
            ])
            ->values()
            ->all();
    }

    /** True when the customer must choose a rail before the provider can charge. */
    public function hasOptions(): bool
    {
        return $this->resolvedOptions() !== [];
    }

    /** Whether `$code` is a rail this provider actually offers. */
    public function hasOption(string $code): bool
    {
        return collect($this->resolvedOptions())->contains(fn ($o) => $o['code'] === $code);
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
