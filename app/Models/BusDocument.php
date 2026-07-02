<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusDocument extends Model
{
    protected $fillable = [
        'bus_id',
        'name',
        'type',
        'file_path',
        'mime_type',
        'size_kb',
        'expires_at',
        'uploaded_by',
    ];

    protected $casts = [
        'expires_at' => 'date',
    ];

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'bus_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
