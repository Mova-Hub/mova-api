<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The extra precision Uber and Bolt collect alongside the geocoded address.
     *
     * A Places result gets a driver to the right street, which is often not
     * enough in Brazzaville: addressing is informal, many buildings are
     * unnumbered, and the useful instruction is a landmark ("après la station
     * Total, portail vert"). Storing it separately from `address` keeps the
     * geocoded value clean for distance calculation while still giving the
     * driver what they actually need.
     */
    public function up(): void
    {
        Schema::table('saved_addresses', function (Blueprint $table) {
            /** Building, floor, door — the "apt 4B" line. */
            $table->string('detail')->nullable()->after('address');
            /** Free-text landmark or directions for the driver. */
            $table->text('directions')->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('saved_addresses', function (Blueprint $table) {
            $table->dropColumn(['detail', 'directions']);
        });
    }
};
