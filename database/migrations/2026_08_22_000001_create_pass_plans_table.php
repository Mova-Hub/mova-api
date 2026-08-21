<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscription products.
     *
     * Modelled as `interval` + `interval_count` rather than a fixed
     * MONTHLY/ANNUAL enum, which is what makes the catalogue editable instead
     * of deployable: a two-week student pass or a ten-day pilgrimage pass is a
     * row, not a migration.
     *
     * `price` is an UNSIGNED INTEGER in whole francs. XAF has no subunit — the
     * smallest coin is one franc — so decimals here would only invite the
     * rounding bugs that money-as-float is famous for, for a fractional part
     * that cannot exist.
     */
    public function up(): void
    {
        Schema::create('pass_plans', function (Blueprint $table) {
            $table->id();
            // Stable machine key. The app and the back-office reference this,
            // so a plan can be renamed for marketing without breaking anything.
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->unsignedInteger('price');
            $table->string('currency', 3)->default('XAF');

            $table->string('interval')->default('month'); // day|week|month|year
            $table->unsignedSmallInteger('interval_count')->default(1);

            // NULL = unlimited travel. A number turns this into a trip bundle,
            // which PRD §6 flags as NOT offline-verifiable — decrementing a
            // counter needs shared state, so bundles require an online check.
            $table->unsignedInteger('trips')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Zones, lines, perks — anything the catalogue grows later without
            // another migration.
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The app's plan list: active plans in display order.
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_plans');
    }
};
