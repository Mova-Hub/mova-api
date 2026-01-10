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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Link to the registered Client
            $table->foreignId('client_id')->constrained()->onDelete('cascade');

            // Status Workflow
            // pending: Just received
            // contacted: Admin called/messaged
            // converted: Turned into a reservation
            // cancelled: Lead lost
            $table->string('status')->default('pending');

            // Trip Details
            $table->string('event_type'); // wedding, school, etc.
            $table->string('origin');
            $table->string('destination');
            $table->date('pickup_date');
            $table->string('pickup_time'); // Keeping as string (06:00) is fine for requests

            // Fleet (JSON: {"coaster": 2, "hiace": 1})
            $table->json('fleet_requirements');

            // Snapshot of contact info (in case it differs from Client profile)
            $table->string('contact_name');
            $table->string('contact_phone');

            // Internal Admin usage
            $table->text('internal_notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
