<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the passenger thought of the trip.
 *
 * Hangs off the RESERVATION, because that is where the trip actually lives:
 * `status`, `started_at`, `completed_at` and `coordinator_id` are all on the
 * reservation, and a rating is a judgement about the journey rather than about
 * the enquiry that produced it.
 *
 * `order_id` is carried alongside it anyway, denormalised on purpose. The app
 * addresses everything by order id, `Trip.id` has never been the reservation
 * uuid, and the back-office list would otherwise need a join to show which
 * booking a rating belongs to.
 *
 * **`unique('reservation_id')` is the anti-double-rating guard**, and it is a
 * constraint rather than a controller check because a double tap on a slow
 * connection sends two requests and a check-then-insert loses that race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_ratings', function (Blueprint $table) {
            $table->id();

            /*
             * UUID, matching `reservations.id`. A plain `foreignId` is a bigint
             * and the constraint silently fails to create against a uuid key.
             * The same note is on the trip_messages migration, which is where
             * this was learned.
             */
            $table->foreignUuid('reservation_id')
                ->constrained('reservations')
                ->cascadeOnDelete();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            /*
             * A snapshot of who ran the trip, and `nullOnDelete` rather than
             * cascade for the reason the coordinator migration gives: somebody
             * who leaves the company must not take the service history with
             * them. The rating survives; the attribution does not.
             */
            $table->foreignId('coordinator_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The headline score. Validated 1..5 in the request rather than as
            // a CHECK, so a bad value is a 422 with a message rather than a 500.
            $table->unsignedTinyInteger('stars');

            // The criteria, each optional. Somebody who gives five stars and
            // taps nothing else has still said something useful, and forcing
            // four more taps is how a rating prompt gets dismissed.
            $table->unsignedTinyInteger('punctuality')->nullable();
            $table->unsignedTinyInteger('cleanliness')->nullable();
            // Not `coordinator_rating`: `coordinator` is the relation name on
            // the model and the two would read as the same thing.
            $table->unsignedTinyInteger('coordinator_score')->nullable();
            $table->unsignedTinyInteger('comfort')->nullable();

            // Quick-pick chips, same use as `waypoints` elsewhere in this schema.
            $table->json('tags')->nullable();
            $table->text('comment')->nullable();

            $table->timestamps();

            // One rating per trip, enforced by the database. See the docblock.
            $table->unique('reservation_id');

            // Per-coordinator averages, and "low scores this week" for the back
            // office. Both are the queries the ratings page actually runs.
            $table->index(['coordinator_id', 'created_at']);
            $table->index(['stars', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_ratings');
    }
};
