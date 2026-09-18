<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the review request went out.
 *
 * A column rather than the cache, for the reason
 * `2026_08_31_000001_add_trip_reminded_at_to_orders_table.php` sets out: this
 * host runs a file cache and `deploy.sh` empties it on every deploy, so a sweep
 * keyed on the cache would ask the same passenger to rate the same trip again
 * the next night. A review request that arrives twice reads as a bug and trains
 * people to ignore the notification.
 *
 * On `reservations` rather than `orders` because the sweep that reads it
 * queries `reservations.status = completed` and `reservations.completed_at`,
 * and a flag belongs beside the thing it is a flag about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'review_requested_at')) {
                $table->timestamp('review_requested_at')->nullable()->after('auto_closed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'review_requested_at')) {
                $table->dropColumn('review_requested_at');
            }
        });
    }
};
