<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Brings an ALREADY-CREATED `payments` table up to the polymorphic shape.
     *
     * The create migration (2026_08_23_000000) was edited in place on the
     * assumption that it had not yet run anywhere. It had — on the production
     * MySQL database — so that table exists with `order_id` and no
     * `payable_type`, and an edited create migration never re-runs. This is the
     * migration that should have been written in the first place.
     *
     * **Fully guarded, and a no-op on a database created from the current
     * create migration.** Both states exist in the wild now: anything migrated
     * before the edit has the old shape, anything after has the new one. A
     * guard per step is what lets one file converge them.
     *
     * The transformation:
     *   order_id (FK, NOT NULL)  →  payable_type + payable_id (string)
     *   provider                 →  provider_code
     *   client_id NOT NULL       →  nullable (walk-in counter payments)
     *   plus channel, kind, parent_payment_id, fee/net amounts,
     *        idempotency_key, expires_at, created_by
     */
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        // Already the target shape — a fresh database, or this has run before.
        if (Schema::hasColumn('payments', 'payable_type')) {
            return;
        }

        $this->renameProviderColumn();
        $this->addNewColumns();
        $this->backfill();
        $this->applyConstraints();
        $this->dropOrderId();
    }

    /**
     * `provider` → `provider_code`.
     *
     * A rename rather than add-backfill-drop: the values are already correct
     * (`mtn_momo`, `cash`), and renaming keeps them without a data pass. The
     * column stops being an enum cast and becomes a plain string keyed to
     * `payment_providers.code`, because providers are rows now.
     */
    private function renameProviderColumn(): void
    {
        if (Schema::hasColumn('payments', 'provider') && ! Schema::hasColumn('payments', 'provider_code')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->renameColumn('provider', 'provider_code');
            });
        }
    }

    /**
     * Everything new, all nullable or defaulted.
     *
     * Nullable first, constraints later: adding a NOT NULL column with no
     * default to a table that already has rows fails outright on MySQL, and
     * `idempotency_key` cannot be unique until every row has a distinct value.
     */
    private function addNewColumns(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'payable_type')) {
                $table->string('payable_type')->nullable()->after('uuid');
                // A string, not the unsignedBigInteger `morphs()` gives:
                // Reservation uses HasUuids, and a bigint would truncate every
                // reservation payment to id 0.
                $table->string('payable_id', 64)->nullable()->after('payable_type');
            }

            if (! Schema::hasColumn('payments', 'channel')) {
                $table->string('channel')->default('app')->after('provider_code');
            }

            if (! Schema::hasColumn('payments', 'kind')) {
                $table->string('kind')->default('full')->after('channel');
            }

            if (! Schema::hasColumn('payments', 'fee_amount')) {
                $table->unsignedInteger('fee_amount')->default(0)->after('amount');
                $table->unsignedInteger('net_amount')->default(0)->after('fee_amount');
            }

            if (! Schema::hasColumn('payments', 'idempotency_key')) {
                $table->string('idempotency_key')->nullable()->after('payer_phone');
            }

            if (! Schema::hasColumn('payments', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('failed_at');
            }
        });

        /*
         * The two self/foreign references go in their own pass.
         *
         * MySQL is happier adding a foreign key in a statement that is not also
         * adding half a dozen plain columns, and a self-referential key in
         * particular is worth isolating so a failure names itself.
         */
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'parent_payment_id')) {
                $table->foreignId('parent_payment_id')->nullable()->after('kind')
                    ->constrained('payments')->nullOnDelete();
            }

            if (! Schema::hasColumn('payments', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('meta')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    private function backfill(): void
    {
        // Every existing payment was against an order — that was the only thing
        // the old schema could point at.
        DB::table('payments')
            ->whereNull('payable_type')
            ->update([
                'payable_type' => Order::class,
                'payable_id' => DB::raw($this->castToChar('order_id')),
            ]);

        // No fees were recorded before, so the whole amount was net.
        DB::table('payments')->where('net_amount', 0)->update([
            'net_amount' => DB::raw('amount'),
        ]);

        /*
         * A distinct key per row, before the unique index goes on.
         *
         * Chunked and set individually rather than in one UPDATE: MySQL has no
         * portable per-row UUID generator across versions, and these tables are
         * small enough that correctness beats cleverness.
         */
        DB::table('payments')
            ->whereNull('idempotency_key')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('payments')
                        ->where('id', $row->id)
                        ->update(['idempotency_key' => 'legacy-' . Str::uuid()]);
                }
            });
    }

    private function applyConstraints(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Now that every row has values, the morph columns can be required.
            $table->string('payable_type')->nullable(false)->change();
            $table->string('payable_id', 64)->nullable(false)->change();
        });

        $this->makeClientIdNullable();

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('idempotency_key');
            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'processing_at']);
            $table->index(['provider_code', 'status']);
        });
    }

    /**
     * `client_id` NOT NULL → nullable, around its foreign key.
     *
     * Nullable because not every payment has an app account behind it: a
     * counter agent collecting cash for a walk-in reservation is a real
     * payment with no client record, and forcing one would either invent a
     * ghost client or push that money out of the ledger entirely.
     *
     * The foreign key is DROPPED and re-added around the change. MySQL will
     * usually permit MODIFY on a column that a constraint references, but
     * "usually" is not a basis on which to run a one-way migration against a
     * money table — and the failure mode is a half-migrated schema at the point
     * where `order_id` has yet to be dropped. Dropping first makes the
     * behaviour the same on every engine and version.
     */
    private function makeClientIdNullable(): void
    {
        try {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['client_id']);
            });
        } catch (Throwable) {
            // Already absent — nothing to restore below either, but re-adding
            // it is harmless and leaves the schema in the intended shape.
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    /**
     * Removes `order_id` and everything hanging off it.
     *
     * Order matters on MySQL: the foreign key holds an implicit index, and the
     * composite `(order_id, status)` index must go before the column it names.
     * Each drop is wrapped because index names differ between databases that
     * were created at different times, and a missing index is not a reason to
     * abandon a migration that has already done the real work.
     */
    private function dropOrderId(): void
    {
        if (! Schema::hasColumn('payments', 'order_id')) {
            return;
        }

        foreach ([
            fn (Blueprint $t) => $t->dropForeign(['order_id']),
            fn (Blueprint $t) => $t->dropIndex(['order_id', 'status']),
        ] as $drop) {
            try {
                Schema::table('payments', $drop);
            } catch (Throwable) {
                // Already absent, or the driver does not name it that way.
            }
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('order_id');
        });
    }

    /** MySQL and SQLite disagree on the cast keyword. */
    private function castToChar(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST({$column} AS TEXT)"
            : "CAST({$column} AS CHAR)";
    }

    /**
     * Deliberately not reversible.
     *
     * Going back means choosing an `order_id` for payments that point at a
     * subscription or a reservation, and there is no honest answer — the rows
     * would have to be dropped. Restore from a backup instead; that is the only
     * safe way to reverse a migration that widened what a table can describe.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Irréversible : restaurez une sauvegarde. Voir le commentaire de down().'
        );
    }
};
