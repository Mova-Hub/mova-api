<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH APPLICATION made the request.
 *
 * The user agent cannot answer this for a native app. Measured against
 * matomo/device-detector, the strings React Native actually sends parse as:
 *
 *     iOS      → no client at all
 *     Android  → "OkHttp 4.12"          (an HTTP library)
 *     Expo Go  → "Chrome Webview 131"   (looks like a browser)
 *
 * So an audit entry from a passenger using the Mova app was indistinguishable
 * from someone poking the API with curl — which is exactly the distinction an
 * audit trail exists to make.
 *
 * Rather than guess harder at a string the HTTP stack chose, the apps now
 * declare themselves in an `X-Mova-Client` header and this column keeps what
 * they sent, verbatim.
 *
 * **Named `declared_client`, not `client`.** Two reasons, both deliberate:
 * `Client` is the customer model in this codebase and a bare `client` column on
 * an audit row would read as a foreign key to it; and the value is
 * client-supplied and forgeable, exactly like the user agent beside it. The
 * column name should not let anyone forget that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('activity_logs', 'declared_client')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            // Stored raw, parsed at read time — the same rule the user agent
            // follows, so a better reader later improves historical rows too.
            $table->string('declared_client', 255)->nullable()->after('user_agent');

            // "Everything that came from the passenger app last week" should be
            // an index scan, not a table scan across an append-only log that
            // only ever grows.
            $table->index('declared_client');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('activity_logs', 'declared_client')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['declared_client']);
            $table->dropColumn('declared_client');
        });
    }
};
