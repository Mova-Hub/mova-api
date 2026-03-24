<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'balance',
        'currency'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Clean helper to check funds before attempting a transaction
    public function hasSufficientFunds(int $amount): bool
    {
        return $this->balance >= $amount;
    }
}
