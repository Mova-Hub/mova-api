<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();

            // Human-friendly code (e.g., BZV-000123)
            $table->string('code', 32)->unique();

            // When the trip happens
            $table->dateTime('trip_date')->index();

            // Simple origin/destination labels (from Mapbox or manual)
            $table->string('from_location', 255);
            $table->string('to_location', 255);

            // Passenger details (not necessarily a registered user)
            $table->string('passenger_name', 120);
            $table->string('passenger_phone', 40);
            $table->string('passenger_email', 190)->nullable();

            // Event
            $table->string('event')->nullable();

            // Seats & pricing
            $table->unsignedSmallInteger('seats')->default(1);
            $table->decimal('price_total', 12, 2)->default(0);

            // Status aligned with UI
            $table->enum('status', ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'])->default('pending')->index();

            // Payment status
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending')->index();

            // Trip track
            $table->dateTime('started_at')->nullable()->index();
            $table->dateTime('completed_at')->nullable()->index();

            // Map / routing
            // Array of { lat: number, lng: number, label: string }
            $table->json('waypoints')->nullable();
            // Rounded km (can come from Directions API or Haversine fallback)
            $table->decimal('distance_km', 8, 2)->nullable();

            // Optional: if you later associate to a Trip model, add a nullable FK
            // $table->uuid('trip_id')->nullable()->index();
            $table->text('internal_notes')->nullable();

            // Auditing / housekeeping
            $table->timestamps();
            $table->softDeletes();

            // Helpful composite index for dashboards
            $table->index(['trip_date', 'status']);
        });

        // If you expect to search passenger frequently:
        Schema::table('reservations', function (Blueprint $table) {
            $table->index(['passenger_phone']);
            $table->index(['passenger_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
