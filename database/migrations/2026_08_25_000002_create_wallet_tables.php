<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mova Credit — a CLOSED-LOOP balance.
     *
     * Read MOVA-WALLET-AND-PAYMENTS.md §3 before changing anything here.
     *
     * Credit is spendable on Mova services and nothing else. There is no
     * top-up, no cash-out and no transfer between clients — not disabled,
     * ABSENT. Adding any of the three turns this into electronic money, which
     * under Règlement 04/18/CEMAC/UMAC/COBAC requires an établissement de
     * paiement licence (roughly seven exist in the entire CEMAC zone).
     *
     * This replaces the `wallets` table, which never worked: it was keyed to
     * `users` (staff) while Client::wallet() declared hasOne(Wallet), so
     * inserting a client wallet violated the foreign key.
     */
    public function up(): void
    {
        Schema::create('wallet_accounts', function (Blueprint $table) {
            $table->id();

            // CLIENTS, not users. The old table's bug.
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * A CACHED PROJECTION of wallet_entries, not the source of truth.
             *
             * Kept because summing an append-only ledger on every screen is
             * needless work, but WalletService::reconcile() re-derives it from
             * entries and reports drift. If the two disagree, the entries win.
             */
            $table->integer('balance')->default(0);
            $table->string('currency', 3)->default('XAF');

            /** active | frozen — frozen blocks spending, never the ledger. */
            $table->string('status')->default('active');

            $table->timestamps();
        });

        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('wallet_account_id')->constrained()->cascadeOnDelete();

            /** credit | debit. Sign lives here so `amount` is always positive. */
            $table->string('direction');
            $table->unsignedInteger('amount');
            /** The running balance after this entry, for reconstruction. */
            $table->integer('balance_after');

            /*
             * Why the credit exists. The enum IS the compliance boundary:
             * every value here originates with Mova, so no entry can represent
             * customer funds received. There is deliberately no `top_up`.
             */
            $table->string('reason');

            /** Payment | Order | PassSubscription — whatever caused it. */
            $table->nullableMorphs('source');

            $table->string('note')->nullable();

            /** Promotional credit lapses; refunded credit does not. */
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * created_at only. THIS TABLE IS APPEND-ONLY — an entry is never
             * updated and never deleted, so `updated_at` would be a column that
             * can only ever lie. A disputed balance has to be reconstructable
             * from entries alone; without that, a bug is indistinguishable from
             * fraud and neither can be unwound.
             */
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_account_id', 'created_at']);
            $table->index(['reason', 'created_at']);
            $table->index('expires_at');
        });

        /*
         * The old table goes, and nothing is migrated out of it.
         *
         * It cannot hold a single valid row: `wallets.user_id` is constrained
         * to `users` while Client::wallet() declared hasOne(Wallet), so every
         * client wallet insert violated the foreign key. Any row that does
         * exist belongs to a staff member and is not a customer balance.
         */
        Schema::dropIfExists('wallets');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('wallet_accounts');
    }
};
