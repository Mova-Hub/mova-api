<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation between a passenger and the coordinator running their trip.
 *
 * Scoped to a RESERVATION, not to the two people. A coordinator runs a
 * different convoy next week with a different client, and a thread keyed on the
 * pair would carry last month's pickup instructions into it. Keyed on the
 * reservation, every trip starts with an empty thread and ends with a record of
 * what was agreed on that trip, which is also what makes it useful evidence
 * when somebody disputes where the coach was told to wait.
 *
 * `sender` is polymorphic because the two participants are different models:
 * `Client` for the passenger app and `User` for the coordinator in the field
 * app. There is no shared parent, and inventing one to avoid a morph would mean
 * rewriting authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_messages', function (Blueprint $table) {
            $table->id();

            // UUID, matching `reservations.id`. A plain foreignId would be a
            // bigint and the constraint would fail to create.
            $table->foreignUuid('reservation_id')
                ->constrained('reservations')
                ->cascadeOnDelete();

            $table->morphs('sender');

            $table->text('body');

            /*
             * When the OTHER party read it.
             *
             * One column rather than a per-participant read table, because a
             * thread here has exactly two sides. It is set by whichever side
             * did not send the message, which is why the controller checks the
             * sender type before writing it.
             */
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The only query this table serves: one thread, oldest first.
            $table->index(['reservation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_messages');
    }
};
