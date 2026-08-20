<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('password');
            $table->string('provider_id')->nullable()->after('provider');

            // One Mova account per provider identity. Composite so the same
            // person can attach both Google and Apple to one account later.
            $table->unique(['provider', 'provider_id']);
        });

        /**
         * Phone was NOT NULL: sign-up always collected it. Social sign-in has no
         * phone number to offer, and demanding one before the account exists
         * would defeat the point of one-tap sign-in — so it becomes optional and
         * is collected later, when a booking actually needs a contact number.
         *
         * Remains UNIQUE. MySQL and Postgres both allow multiple NULLs in a
         * unique index, so accounts without a phone don't collide.
         */
        Schema::table('clients', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);
            $table->dropColumn(['provider', 'provider_id']);
        });

        // Deliberately NOT reverting phone to NOT NULL: by the time this runs,
        // social accounts with a null phone may exist and the change would fail.
        // Backfill those before tightening the column again.
    }
};
