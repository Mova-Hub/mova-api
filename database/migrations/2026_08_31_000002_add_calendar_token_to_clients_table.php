<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secret in a client's calendar subscription URL.
 *
 * A calendar feed is fetched by Google's and Apple's servers, not by the app,
 * so it cannot carry a bearer token: those servers will not send an
 * Authorization header, and there is no session to fall back on. The URL itself
 * has to be the credential, which is how every calendar feed on the internet
 * works.
 *
 * That makes this column a password in all but name, and it is treated as one:
 * 48 random characters, unique, hidden from every serialization, and rotatable
 * so a client who has shared a link by accident can revoke it. It is null until
 * somebody first asks for their feed, so no token exists for an account that
 * never uses the feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Named explicitly: SQLite cannot drop a column that an index still
            // references, and the unique index is not removed with the column.
            $table->dropUnique(['calendar_token']);
            $table->dropColumn('calendar_token');
        });
    }
};
