<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stable pseudonymous identifier for the Pass system.
     *
     * The card carries this, not the client's integer id and certainly not
     * their phone number. Three reasons:
     *
     *  - The chip is world-readable. Anything on it is public, so it must be a
     *    value that leaks nothing: a random UUID identifies a subscriber to
     *    Mova and to nobody else.
     *  - Sequential ids on a public token are an enumeration invitation.
     *  - It must survive both card replacement and renewal, so it belongs to
     *    the person, not to a card or a subscription.
     *
     * Nullable and generated lazily on first Pass use: the great majority of
     * clients only ever charter a bus and will never own a card.
     *
     * PRD §6 modelled subscribers as their own table with their own name and
     * phone. Collapsed into `clients` deliberately — one identity means the
     * counter cannot create a second, divergent record for someone who already
     * has an app account.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->uuid('pass_uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('pass_uuid');
        });
    }
};
