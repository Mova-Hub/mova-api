<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this order's client was last reminded about the trip itself.
 *
 * One column rather than one per reminder offset, because the rule that uses it
 * is "at most one trip reminder per day": the sweep sends on the eve and again
 * on the morning, and comparing this stamp against the start of today
 * distinguishes those two sends without storing which offset each was.
 *
 * A column and not the cache. `SendPaymentReminders` caps itself through
 * `Cache::put`, which is right for a soft daily cap on dunning, but a trip
 * reminder that fires twice reads as a bug to the client, and on this host the
 * cache is a file store that `cache:clear` empties during any deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('trip_reminded_at')->nullable()->after('internal_notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('trip_reminded_at');
        });
    }
};
