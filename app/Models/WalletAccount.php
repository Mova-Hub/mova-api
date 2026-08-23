<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's Mova Credit balance.
 *
 * `balance` is a CACHED PROJECTION of `wallet_entries`, never the source of
 * truth. WalletService::reconcile() re-derives it; if the two disagree, the
 * entries win.
 *
 * @see MOVA-WALLET-AND-PAYMENTS.md §3 — closed-loop, and why there is no top-up.
 */
class WalletAccount extends Model
{
    protected $fillable = ['client_id', 'balance', 'currency', 'status'];

    protected $casts = [
        'balance' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class)->latest('created_at');
    }

    public function isSpendable(): bool
    {
        return $this->status === 'active';
    }

    public function hasSufficientFunds(int $amount): bool
    {
        return $this->balance >= $amount;
    }
}
