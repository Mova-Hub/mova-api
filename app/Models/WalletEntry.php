<?php

namespace App\Models;

use App\Domain\Wallet\Enums\WalletReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One movement of Mova Credit. **Append-only.**
 *
 * Never updated, never deleted. `$timestamps = false` with only `created_at`
 * because an `updated_at` on an immutable row is a column that can only ever
 * lie about what happened.
 *
 * The entries are the ledger; `wallet_accounts.balance` is a cache of them. A
 * disputed balance has to be reconstructable from these rows alone — without
 * that, a bug is indistinguishable from fraud and neither can be unwound.
 */
class WalletEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'wallet_account_id', 'direction', 'amount', 'balance_after',
        'reason', 'source_type', 'source_id', 'note', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'reason' => WalletReason::class,
        'expires_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $entry->uuid ??= (string) Str::uuid();
        });

        /*
         * The immutability guard, enforced by the model rather than by
         * convention. A future `->update()` on an entry throws instead of
         * quietly rewriting history — the one thing an audit ledger must never
         * permit, and the one thing an ORM makes trivially easy.
         */
        static::updating(fn () => throw new \LogicException(
            'Les écritures du portefeuille sont immuables : créez une écriture inverse.'
        ));

        static::deleting(fn () => throw new \LogicException(
            'Les écritures du portefeuille ne peuvent pas être supprimées.'
        ));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class, 'wallet_account_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Signed value, for summing. Storage keeps `amount` positive. */
    public function signedAmount(): int
    {
        return $this->direction === 'credit' ? $this->amount : -$this->amount;
    }
}
