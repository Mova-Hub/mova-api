<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The return leg of a round trip.
 *
 * `orders` has carried `return_date` and `return_time` since 2026-08-21 and the
 * app has collected them since — but `reservations` had nowhere to put them, so
 * **converting a round-trip request silently produced a one-way booking**. The
 * customer had asked for a return, agreed a price that included it (the quote
 * engine bills a return leg as the same road driven twice), and the reservation
 * that came out the other side said nothing about it. Dispatch had no way to
 * know a vehicle was needed for the journey back.
 *
 * **One column, not two.** `orders` splits date and time because the app
 * collects them separately; `reservations.trip_date` is already a `dateTime`,
 * so the return matches it. Splitting here would mean two representations of
 * the same idea in one table.
 *
 * **No `round_trip` boolean.** Whether a trip is a round trip is exactly
 * "does it have a return date" — the same rule `OrderRequestController` uses
 * when pricing (`roundTrip: ! empty($data['return_date'])`). A separate flag
 * would be a second source of truth that can disagree with the first, and the
 * disagreement would show up as a billing dispute.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reservations', 'return_date')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dateTime('return_date')->nullable()->after('trip_date');

            // "Which vehicles come back on Sunday" is a dispatch question asked
            // every week, so it is an index rather than a scan.
            $table->index('return_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservations', 'return_date')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['return_date']);
            $table->dropColumn('return_date');
        });
    }
};
