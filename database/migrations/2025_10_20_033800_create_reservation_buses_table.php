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
        Schema::create('reservation_buses', function (Blueprint $table) {
            $table->uuid('reservation_id');
            $table->unsignedBigInteger('bus_id');

            // Prevent duplicates (same bus twice on one reservation)
            $table->primary(['reservation_id', 'bus_id']);

            // FKs, your buses use UUIDs
            $table->foreign('reservation_id')
                  ->references('id')->on('reservations')
                  ->onDelete('cascade');

            $table->foreign('bus_id')
                  ->references('id')->on('buses')
                  ->onDelete('restrict'); // or cascade if you prefer

            // If you need to record when the association was created:
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_buses');
    }
};
