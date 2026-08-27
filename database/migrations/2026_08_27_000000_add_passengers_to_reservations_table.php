<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many people are actually travelling.
 *
 * `orders.passengers` has been collected since 2026-08-21 — validated
 * `min:1|max:300` and used at booking to check the requested fleet has enough
 * seats — and then **dropped on the floor at conversion**. `reservations` had
 * no equivalent column, so the head count survived only via `order_id`, and
 * every screen built on a reservation had to either join back or show nothing.
 *
 * **Not the same thing as `seats`.** `seats` is the capacity of the vehicles
 * attached; `passengers` is how many people are expected. The pair is the whole
 * point — "22 passagers · 30 places" is the sentence dispatch needs, and either
 * number alone hides the question of whether everybody has a seat.
 *
 * **Also not `passenger_name`.** Those columns describe ONE named contact for
 * the booking. Conflating a contact with a head count is how a coach gets sent
 * for a party of one.
 *
 * Nullable, because a reservation created directly through
 * `ReservationController@store` (a walk-in at the counter) may genuinely not
 * know a head count yet. A default of 1 would assert something nobody said.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reservations', 'passengers')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            // Mirrors `orders.passengers` exactly — same type, same nullability,
            // so copying one to the other at conversion cannot truncate.
            $table->unsignedSmallInteger('passengers')->nullable()->after('seats');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservations', 'passengers')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('passengers');
        });
    }
};
