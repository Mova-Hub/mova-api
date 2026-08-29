<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is actually responsible for this trip happening.
 *
 * A reservation has carried a client, a route, a price and a set of vehicles
 * since it was created — and nothing at all about the person who has to gather
 * those vehicles on the morning, meet the client, and answer the phone when a
 * driver is late. That person exists; they were simply not in the database, so
 * "who is running Saturday's Pointe-Noire trip" was a WhatsApp question and an
 * unanswered one was only discovered by the client.
 *
 * Nullable, because every row that already exists has no coordinator and
 * inventing one would be worse than a null. New conversions require one — that
 * rule belongs in the convert dialog and the request validation, not in a
 * NOT NULL that would refuse to let the migration run.
 *
 * `nullOnDelete`: a coordinator who leaves the company should not take the
 * reservation history with them. The trip still happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reservations', 'coordinator_id')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('coordinator_id')
                ->nullable()
                ->after('client_id')
                ->constrained('users')
                ->nullOnDelete();

            // The field app's only query is "my missions", ordered by date.
            $table->index(['coordinator_id', 'trip_date'], 'reservations_coordinator_trip_date_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservations', 'coordinator_id')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_coordinator_trip_date_index');
            $table->dropConstrainedForeignId('coordinator_id');
        });
    }
};
