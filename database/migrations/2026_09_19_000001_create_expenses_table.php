<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money leaving Mova. **Append-only, on the same terms as wallet_entries.**
     *
     * The inflow side of the business has been in the database since
     * `create_payments_table`: every collection, the fee it cost, every refund.
     * The outflow side lived in spreadsheets, which means no report in this
     * system could answer whether a month made money, and the per-vehicle
     * revenue on the fleet dashboard had no cost to sit against.
     *
     * Three decisions worth knowing before changing this table:
     *
     *  1. **Cash basis.** `paid_at` is when money moved, and it is the date every
     *     report groups by. `created_at` is when somebody typed it in, which is
     *     routinely days later and is useless for a P&L. They are separate
     *     columns because conflating them is the single most common way a
     *     hand-kept ledger starts disagreeing with the bank.
     *
     *  2. **Direction, not signed amounts.** `amount` is always positive and
     *     `direction` carries the sign, exactly as `wallet_entries` does. A
     *     supplier credit note is a `credit` row pointing at the `debit` it
     *     partially undoes, never an edit to the original and never a negative
     *     number that a forgotten `ABS()` can silently flip.
     *
     *  3. **Whole francs, as integers.** XAF has no minor unit. Every amount in
     *     this system is an integer for that reason, and a `decimal` here would
     *     invite floating-point drift into the only table that subtracts.
     */
    public function up(): void
    {
        /*
         * Clears the wreckage of the first attempt at this migration.
         *
         * MySQL has no transactional DDL, so when the reservation foreign key
         * below was rejected (issue #26) the statements before it were NOT
         * rolled back. That leaves an `expenses` table that exists and is
         * missing a constraint, with no row in `migrations` recording it,
         * because the migration never finished. A plain retry would then fail
         * again, this time on "table already exists", which is a confusing
         * second error for what is really the first one.
         *
         * Guarded on the row count, so this can only ever discard a table that
         * has nothing in it. If a real ledger is somehow present, the create
         * below fails loudly rather than deleting anybody's accounts, which is
         * the only acceptable failure mode for this table.
         */
        if (Schema::hasTable('expenses') && DB::table('expenses')->count() === 0) {
            Schema::drop('expenses');
        }

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /**
             * `ExpenseCategory`, a PHP enum rather than a foreign key.
             *
             * Stored as its string value so a row stays readable in a database
             * client and survives the enum being reordered. See the enum's
             * docblock for why the list is not a table.
             */
            $table->string('category');

            /** debit = money out, credit = money coming back (a reversal). */
            $table->string('direction')->default('debit');

            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('XAF');

            /** How it left: especes | mobile_money | virement | cheque. */
            $table->string('method');

            /*
             * THE CASH-BASIS DATE. Every report groups by this, not created_at.
             *
             * A date rather than a timestamp: nobody knows or cares which minute
             * a fuel receipt was settled, and a timestamp would invite a
             * timezone bug into the column that decides which month a cost lands
             * in.
             */
            $table->date('paid_at');

            /*
             * Cost attribution. Both nullable, because plenty of real costs
             * attach to neither: the office rent belongs to no vehicle and no
             * trip.
             *
             * `nullOnDelete` rather than cascade on both. Deleting a bus must
             * not delete the money Mova spent on it, or scrapping a vehicle
             * quietly rewrites the year's fuel total.
             */
            $table->foreignId('bus_id')->nullable()->constrained()->nullOnDelete();

            /*
             * `uuid`, NOT `foreignId`, because `reservations.id` is a uuid.
             *
             * `foreignId()` creates an unsigned bigint, and MySQL refuses a
             * foreign key whose column type does not match the one it
             * references: "errno 150, Foreign key constraint is incorrectly
             * formed". This is the shape `reservation_buses` has always used.
             *
             * SQLite does not enforce that match, so the original version of
             * this migration created cleanly in the test suite and failed on
             * the first real deploy. See issue #26.
             */
            $table->uuid('reservation_id')->nullable();
            $table->foreign('reservation_id')->references('id')->on('reservations')
                ->nullOnDelete();

            /*
             * Who was paid, as text.
             *
             * No supplier table, on purpose: a fuel station, a garage and a
             * landlord are not `users`, and inventing a counterparty model to
             * hold a name nobody will ever query relationally is work that buys
             * nothing today. When statements per supplier are actually wanted,
             * this column is what tells us which names are worth promoting.
             */
            $table->string('supplier_name')->nullable();

            /** Receipt, invoice, cheque or transfer number. */
            $table->string('reference')->nullable();

            /*
             * Required in the request, nullable here.
             *
             * Same posture as `wallet_entries.note`: an unexplained movement of
             * money is indistinguishable from a mistake six months later, so the
             * controller insists on one, while the column stays nullable so an
             * import or a future system-generated row is not blocked by it.
             */
            $table->string('note')->nullable();

            /* The receipt image, the ONE mutable part of a row. */
            $table->string('receipt_path')->nullable();
            $table->string('receipt_mime')->nullable();
            $table->unsignedInteger('receipt_size_kb')->nullable();

            /**
             * The debit this row reverses, when it is a reversal.
             *
             * Self-referential and nullable. Present on `credit` rows only, and
             * `Expense::reverses` is what makes "this cost was undone on 3
             * September, by whom and why" answerable without reading two rows
             * and guessing they belong together.
             */
            $table->foreignId('reverses_id')->nullable()
                ->constrained('expenses')->nullOnDelete();

            /*
             * Who recorded it. `nullOnDelete`, never cascade: a staff member
             * leaving must not take the company's expense history with them.
             */
            $table->foreignId('recorded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * Both timestamps, unlike `wallet_entries` which keeps only
             * `created_at`.
             *
             * The difference is real rather than an inconsistency: a receipt can
             * legitimately be photographed after the expense is entered, so
             * `receipt_path` is genuinely mutable and `updated_at` genuinely
             * means "when the receipt was attached". Every financial field is
             * frozen by the guard in the Expense model; see it before adding a
             * column here, because a new field is immutable by default and has
             * to be allowed through deliberately.
             */
            $table->timestamps();

            /** The reporting index: every P&L query is a date range. */
            $table->index(['paid_at', 'category']);
            $table->index(['category', 'paid_at']);
            /** Cost per vehicle, the figure the fleet dashboard was missing. */
            $table->index(['bus_id', 'paid_at']);
            $table->index(['reservation_id']);
            $table->index(['direction', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
