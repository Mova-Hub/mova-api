<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stops the expiry warning being sent every night for a week.
 *
 * `warnExpiring()` runs nightly and selects every Pass expiring within a few
 * days. Without a record of having warned somebody, that set is the same set
 * tomorrow, so a client three days out would receive the same message three
 * times and learn to ignore it.
 *
 * A timestamp rather than a boolean, because "when were they told" is the
 * question support actually asks, and because it lets the window be widened
 * later without re-warning everyone already warned.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pass_subscriptions', 'notified_expiring_at')) {
            return;
        }

        Schema::table('pass_subscriptions', function (Blueprint $table) {
            $table->timestamp('notified_expiring_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pass_subscriptions', 'notified_expiring_at')) {
            return;
        }

        Schema::table('pass_subscriptions', function (Blueprint $table) {
            $table->dropColumn('notified_expiring_at');
        });
    }
};
