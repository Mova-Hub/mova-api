<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push tokens for STAFF phones. Clients have had these since day one.
 *
 * `client_fcm_tokens` exists (inside the clients migration) and
 * `Client::routeNotificationForFcm()` reads it, which is why a passenger gets a
 * push when their trip is confirmed. `User` had no equivalent, so a coordinator
 * assigned a convoy could only be reached by e-mail — and nobody checks e-mail
 * on a bus at six in the morning.
 *
 * A deliberate mirror of the client table, down to the column names, so the two
 * notification routes read identically and neither becomes the odd one out.
 * Separate tables rather than one polymorphic one: the FK is what guarantees a
 * token dies with its owner, and a `morphs` column cannot cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_fcm_tokens')) {
            return;
        }

        Schema::create('user_fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Globally unique, as on the client table.
             *
             * A device handed from one inspector to the next re-registers the
             * same token; unique + updateOrCreate means it MOVES to the new
             * owner rather than notifying both. Without it, the person who
             * handed the phone over keeps receiving the missions.
             */
            $table->string('fcm_token', 255)->unique();
            $table->string('type')->default('fcm'); // fcm | expo
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_fcm_tokens');
    }
};
