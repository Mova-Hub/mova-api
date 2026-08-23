<?php

use App\Domain\Payment\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Folds the third ledger into the one.
     *
     * `transactions` recorded back-office collections against a reservation and
     * fed the dashboard's revenue figure. `payments` recorded app-initiated
     * payments. Neither could see the other, which means **the dashboard's
     * "collecté" number has never counted a single payment made in the app.**
     * That is the bug this fixes; the tidier architecture is a side effect.
     *
     * The old table is LEFT IN PLACE, dropped by a later migration once the
     * counts have been verified against production data. A backfill that also
     * destroys its own source cannot be checked after the fact.
     */
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $methodToProvider = [
            'cash' => 'cash',
            'mobile_money' => 'mobile_money_manual',
            'bank_transfer' => 'bank_transfer',
            'check' => 'cheque',
        ];

        $statusMap = [
            'completed' => PaymentStatus::Succeeded->value,
            'pending' => PaymentStatus::Pending->value,
            'failed' => PaymentStatus::Failed->value,
        ];

        DB::table('transactions')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($methodToProvider, $statusMap) {
                $now = now();

                foreach ($rows as $row) {
                    // `reservations` uses HasUuids, so this is a UUID string.
                    // Casting it to int — the obvious-looking thing to do with
                    // a column named *_id — would collapse every row onto 0.
                    $reservationId = (string) $row->reservation_id;

                    $reservation = DB::table('reservations')->where('id', $reservationId)->first();
                    if (! $reservation) {
                        // An orphan row cannot become a payment against
                        // nothing. Left behind in `transactions` for a human.
                        continue;
                    }

                    $status = $statusMap[$row->status] ?? PaymentStatus::Pending->value;
                    $succeeded = $status === PaymentStatus::Succeeded->value;

                    // Amounts were decimal(10,2); XAF has no subunit, so this
                    // rounds rather than truncates — a stored 4999.99 is a
                    // 5000 franc payment, not 4999.
                    $amount = (int) round((float) $row->amount);

                    DB::table('payments')->insert([
                        'uuid' => (string) Str::uuid(),
                        'payable_type' => 'App\\Models\\Reservation',
                        'payable_id' => $reservationId,
                        'client_id' => $reservation->client_id ?? null,
                        'provider_code' => $methodToProvider[$row->method] ?? 'cash',
                        'channel' => 'back_office',
                        'kind' => 'full',
                        'status' => $status,
                        'amount' => $amount,
                        'fee_amount' => 0,
                        'net_amount' => $amount,
                        'currency' => 'XAF',
                        // Deterministic, so re-running this migration cannot
                        // duplicate a row — the unique index refuses it.
                        'idempotency_key' => 'legacy-txn-' . $row->id,
                        'provider_reference' => $row->reference ?: null,
                        'paid_at' => $succeeded ? $row->created_at : null,
                        'meta' => json_encode([
                            'imported_from' => 'transactions',
                            'legacy_id' => $row->id,
                            'note' => $row->note,
                        ]),
                        'created_at' => $row->created_at ?? $now,
                        'updated_at' => $row->updated_at ?? $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('payments')->where('idempotency_key', 'like', 'legacy-txn-%')->delete();
    }
};
