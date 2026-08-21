<?php

namespace App\Models;

use App\Domain\Pass\Enums\ScanSource;
use App\Domain\Pass\Enums\ScanVerdict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassScan extends Model
{
    protected $fillable = [
        'client_reference', 'pass_card_id', 'client_id', 'pass_subscription_id',
        'chip_uid', 'source', 'verdict', 'reason',
        'inspector_id', 'bus_line', 'device_id',
        'latitude', 'longitude',
        'scanned_at', 'synced_at', 'offline_duration_minutes', 'metadata',
    ];

    protected $casts = [
        'source' => ScanSource::class,
        'verdict' => ScanVerdict::class,
        'latitude' => 'float',
        'longitude' => 'float',
        'scanned_at' => 'immutable_datetime',
        'synced_at' => 'immutable_datetime',
        'offline_duration_minutes' => 'integer',
        'metadata' => 'array',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(PassCard::class, 'pass_card_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PassSubscription::class, 'pass_subscription_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /**
     * Boardings only.
     *
     * A subscriber checking their own card in the app is not a trip, and
     * counting it as one would inflate every ridership figure the business
     * plans against.
     */
    public function scopeFareEvents(Builder $query): Builder
    {
        return $query->where('source', ScanSource::Control->value);
    }

    public function scopeRefused(Builder $query): Builder
    {
        return $query->where('verdict', '!=', ScanVerdict::Accepted->value);
    }
}
