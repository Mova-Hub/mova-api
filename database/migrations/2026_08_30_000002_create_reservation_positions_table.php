<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the convoy is, so a client can watch it come.
 *
 * The coordinator's phone reports this while the trip runs. One stream per
 * reservation — the convoy as a whole, not a dot per vehicle — because the
 * coordinator is the one person guaranteed to be travelling with it and already
 * holding an authenticated device. Drivers are `FLEET_ROLES`: they have no
 * login and no app.
 *
 * **`bus_id` is nullable from day one, and that is the point.** Null means "the
 * convoy, as reported by the coordinator", which is every row today. When
 * driver devices eventually exist, per-bus rows drop straight in beside them
 * with no migration and no backfill — the shape already allows for it.
 *
 * This is personal location data about an employee. Three things follow, and
 * all three are enforced elsewhere in this change rather than being left as
 * good intentions:
 *
 *  - it is only ever written between `start` and `complete`;
 *  - the passenger endpoint only serves a position while the reservation is
 *    `in_progress` — where a coordinator went after the trip is their own
 *    business;
 *  - `positions:prune` deletes the trail once the trip is a week old.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservation_positions')) {
            return;
        }

        Schema::create('reservation_positions', function (Blueprint $table) {
            $table->id();

            // UUID, matching `reservations.id`. Cascade: a deleted reservation
            // has no trail worth keeping.
            $table->foreignUuid('reservation_id')
                ->constrained('reservations')
                ->cascadeOnDelete();

            // Null = the convoy. See the class docblock.
            $table->foreignId('bus_id')->nullable()->constrained('buses')->nullOnDelete();

            // Who reported it. Not decorative — it is the difference between a
            // position and an attributable one.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * `decimal`, not `float`.
             *
             * 7 decimal places is ~1cm at the equator, far beyond what a phone
             * GPS resolves, and decimal avoids the drift that makes two
             * "identical" float positions compare unequal.
             */
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            // All optional: a fix indoors or on a cold start has none of them,
            // and refusing the position would lose the only thing it did know.
            $table->decimal('heading', 5, 2)->nullable();   // degrees, 0–360
            $table->decimal('speed', 6, 2)->nullable();     // m/s as the OS reports it
            $table->decimal('accuracy', 7, 2)->nullable();  // metres

            /*
             * The DEVICE clock, not the server's.
             *
             * A coordinator drives through a dead zone and the queue flushes
             * six positions at once; stamping them on arrival would draw a bus
             * that teleported. The device knows when it was actually there.
             */
            $table->timestamp('recorded_at')->index();

            $table->timestamps();

            // The only two queries: "latest for this reservation" and the prune.
            $table->index(['reservation_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_positions');
    }
};
