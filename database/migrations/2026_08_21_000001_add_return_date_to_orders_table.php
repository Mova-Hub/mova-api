<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Charters are frequently return trips — a wedding shuttle brings guests
     * back, a school trip returns the same evening. The orders table only had
     * a single `pickup_date`, so a return leg could not be expressed at all and
     * had to be agreed by phone afterwards.
     *
     * Nullable: plenty of charters genuinely are one-way, and every existing
     * row predates the field.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('return_date')->nullable()->after('pickup_date');
            $table->string('return_time')->nullable()->after('return_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['return_date', 'return_time']);
        });
    }
};
