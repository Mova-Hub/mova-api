<?php

namespace App\Models;

use App\Domain\Pass\Enums\PlanInterval;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PassPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description',
        'price', 'currency',
        'interval', 'interval_count', 'trips',
        'is_active', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'price' => 'integer',
        'interval' => PlanInterval::class,
        'interval_count' => 'integer',
        'trips' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PassSubscription::class);
    }

    /** What the app is allowed to offer. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('price');
    }

    /** When a subscription starting at `$from` under this plan would expire. */
    public function expiryFrom(CarbonInterface $from): CarbonInterface
    {
        return $this->interval->advance($from, $this->interval_count);
    }

    /**
     * A trip bundle cannot be verified offline.
     *
     * Decrementing a counter needs shared state, and an inspector's phone has
     * none — two buses would each accept the last trip. PRD §6 flags this as
     * unresolved; until it is, bundles must be gated to online verification
     * rather than assumed to work.
     */
    public function isBundle(): bool
    {
        return $this->trips !== null;
    }

    public function durationLabel(): string
    {
        return $this->interval_count . ' ' . $this->interval->label($this->interval_count);
    }
}
