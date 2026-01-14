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
            $table->enum('event', ['school_trip', 'university_trip', 'educational_tour', 'student_transport', 'wedding', 'funeral', 'birthday', 'baptism', 'family_meeting', 'conference', 'seminar', 'company_trip', 'business_mission', 'staff_shuttle', 'football_match', 'sports_tournament', 'concert', 'festival', 'school_competition', 'tourist_trip', 'group_excursion', 'pilgrimage', 'site_visit', 'airport_transfer', 'election_campaign', 'administrative_mission', 'official_trip', 'private_transport', 'special_event', 'simple_rental'])->nullable();

            // Seats & pricing
            $table->unsignedSmallInteger('seats')->default(1);
            $table->decimal('price_total', 12, 2)->default(0);

            // Status aligned with UI
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'processing'])->default('pending')->index();

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
