<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A trip can lapse, and a running trip can be closed by the system.
 *
 * Two gaps this fills.
 *
 * **`expired` on the reservation.** `orders.status` has had `expired` since the
 * lead sweep landed, but `reservations.status` is an ENUM of five values and
 * none of them fits "the travel date passed and nobody ever started this". The
 * only legal target was `cancelled`, which this codebase refuses on purpose:
 * cancelling is an act somebody decided, the back office reads cancellations as
 * lost business, and ops are measured on them. Filing a lapsed trip there would
 * blame the sales team for a trip nobody declined.
 *
 * **`closing_notified_at`.** The "this trip should be closed" notice fires once
 * when a running trip passes its end, and the auto-close follows a day later. A
 * column rather than the cache, for the reason spelled out in
 * `2026_08_31_000001_add_trip_reminded_at_to_orders_table.php`: this host uses a
 * file cache that `cache:clear` empties on every deploy, and a nag that fires
 * twice reads as a bug.
 *
 * **`auto_closed_at`.** Distinguishes a trip a coordinator finished from one a
 * cron gave up on. That difference matters downstream: a trip nobody actually
 * ended should not be prompting the passenger to rate it.
 *
 * `enum(...)->change()` rather than raw SQL: Laravel 12 handles it natively on
 * both drivers, and SQLite enforces an enum with a CHECK constraint rather than
 * storing free text, so widening it any other way fails only on the
 * verification database. See api/AGENTS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'confirmed',
                'in_progress',
                'completed',
                'cancelled',
                'expired',
            ])->default('pending')->change();
        });

        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'closing_notified_at')) {
                $table->timestamp('closing_notified_at')->nullable()->after('completed_at');
            }

            if (! Schema::hasColumn('reservations', 'auto_closed_at')) {
                $table->timestamp('auto_closed_at')->nullable()->after('closing_notified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'auto_closed_at')) {
                $table->dropColumn('auto_closed_at');
            }

            if (Schema::hasColumn('reservations', 'closing_notified_at')) {
                $table->dropColumn('closing_notified_at');
            }
        });

        /*
         * Rows already sitting at `expired` have to move BEFORE the value
         * disappears, or the narrowed CHECK constraint refuses to apply and the
         * rollback fails halfway, which turns a bad deploy into an outage.
         *
         * `cancelled` is the only legal target, and it is what these rows would
         * have been before this migration existed. It loses the
         * expired-versus-cancelled distinction, which is exactly why rolling
         * back is not free.
         */
        DB::table('reservations')
            ->where('status', 'expired')
            ->update(['status' => 'cancelled']);

        Schema::table('reservations', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'confirmed',
                'in_progress',
                'completed',
                'cancelled',
            ])->default('pending')->change();
        });
    }
};
