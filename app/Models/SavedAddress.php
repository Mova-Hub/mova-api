<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedAddress extends Model
{
    use HasFactory;

    /** The shortcuts the app pins; anything else is `custom`. */
    public const FIXED_KINDS = ['home', 'work', 'school'];

    protected $fillable = [
        'client_id',
        'kind',
        'label',
        'address',
        'detail',
        'directions',
        'lat',
        'lng',
        'place_id',
    ];

    protected $casts = [
        // Kept as floats so the client gets numbers, not decimal strings.
        'lat' => 'float',
        'lng' => 'float',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
