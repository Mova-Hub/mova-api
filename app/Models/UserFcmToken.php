<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff device that can receive a push. Mirrors `ClientFcmToken` exactly.
 */
class UserFcmToken extends Model
{
    protected $fillable = ['user_id', 'fcm_token', 'type', 'device_name', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
