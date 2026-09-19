<?php

namespace App\Models;

use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Finance\Enums\ExpenseMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One movement of money out of Mova. Financially immutable.
 *
 * The same posture as WalletEntry, for the same reason: a ledger whose rows can
 * be edited proves nothing, and a wrong figure is corrected by a reversing
 * entry rather than by rewriting the original. The difference is that an
 * expense carries a receipt, which is genuinely attached later, so the guard
 * below freezes the financial fields by ALLOWLIST rather than freezing the
 * whole row.
 *
 * A column added to this table is immutable by default. Letting it change means
 * adding it to MUTABLE deliberately, which is the point: the decision to make a
 * field editable should be a decision, not an oversight.
 */
class Expense extends Model
{
    /**
     * The only fields an update may touch.
     *
     * Receipt metadata, and nothing else. Not the amount, not the date, not the
     * category, not the attribution to a bus or a trip: those are the figures
     * a report is built from, and if any of them can move, no report printed
     * yesterday can be reproduced today.
     */
    private const MUTABLE = ['receipt_path', 'receipt_mime', 'receipt_size_kb', 'updated_at'];

    protected $fillable = [
        'uuid', 'category', 'direction', 'amount', 'currency', 'method', 'paid_at',
        'bus_id', 'reservation_id', 'supplier_name', 'reference', 'note',
        'receipt_path', 'receipt_mime', 'receipt_size_kb', 'reverses_id', 'recorded_by',
    ];

    protected $casts = [
        'category' => ExpenseCategory::class,
        'method' => ExpenseMethod::class,
        'amount' => 'integer',
        'receipt_size_kb' => 'integer',
        'paid_at' => 'immutable_date',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $expense) {
            $expense->uuid ??= (string) Str::uuid();
        });

        /*
         * The immutability guard, enforced by the model rather than by
         * convention, because an ORM makes rewriting history a one-liner and
         * nothing else in the request path would notice.
         */
        static::updating(function (self $expense) {
            $touched = array_diff(array_keys($expense->getDirty()), self::MUTABLE);

            if ($touched !== []) {
                throw new \LogicException(
                    'Une dépense est immuable (' . implode(', ', $touched)
                    . ') : enregistrez une écriture inverse.'
                );
            }
        });

        static::deleting(fn () => throw new \LogicException(
            'Une dépense ne peut pas être supprimée : enregistrez une écriture inverse.'
        ));
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The debit this row undoes, on a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function isReversal(): bool
    {
        return $this->direction === 'credit';
    }

    /**
     * The effect on the P&L: negative for money out, positive for money back.
     *
     * The one place the sign is applied. Every caller that needs to add
     * expenses together uses this rather than reading `amount`, because
     * `amount` is unsigned and a reversal added naively would double the cost
     * it was meant to cancel.
     */
    public function signedAmount(): int
    {
        return $this->isReversal() ? $this->amount : -$this->amount;
    }

    public function receiptUrl(): ?string
    {
        return $this->receipt_path ? Storage::disk('public')->url($this->receipt_path) : null;
    }
}
