<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two roles the field team needs and the database has never had.
 *
 * `users.role` has been `['driver','owner','conductor','agent','admin']` since
 * the first migration. Neither of the people who actually deliver the service
 * appears in it:
 *
 *  - **coordinator** — owns one reservation end to end. Gathers the vehicles,
 *    meets the client, rides with the convoy. Nothing recorded who this was, so
 *    "who is running the Pointe-Noire trip on Saturday" was a WhatsApp question.
 *  - **controller** — rides a bus and checks Pass subscriptions. This is what
 *    `control/` already does, and inspectors have been signing in as `agent` to
 *    do it — which hands a bus inspector the clients list, the payments ledger
 *    and the settings screen, because `agent` is a back-office role.
 *
 * **Deliberately not reusing `conductor`.** In this schema a conductor is a
 * fleet record attached to a vehicle (`buses.assigned_conductor_id`) and never
 * logs in; a controller is an operator with a token. Same word in French
 * transport, two different rows, and conflating them would put a login on every
 * fleet record in the system.
 *
 * **`->change()`, not raw `ALTER TABLE ... MODIFY`.** The first draft of this
 * migration branched on the driver and treated SQLite as a no-op, on the
 * assumption that SQLite has no ENUM type and stores the column as free text.
 * It does not: Laravel's SQLite grammar renders an enum as
 * `varchar check ("role" in ('driver','owner',...))`, and inserting
 * `coordinator` fails with `CHECK constraint failed: role`. Laravel 12 changes
 * columns natively — no doctrine/dbal — and knows how to rebuild an SQLite
 * table to replace that constraint, which is exactly the work nobody should be
 * writing by hand.
 */
return new class extends Migration
{
    /** The full set, in the order the original migration declared them. */
    private const ROLES = ['driver', 'owner', 'conductor', 'agent', 'admin', 'coordinator', 'controller'];

    private const PREVIOUS_ROLES = ['driver', 'owner', 'conductor', 'agent', 'admin'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', self::ROLES)->change();
        });
    }

    public function down(): void
    {
        /*
         * Anyone already holding a role that is about to disappear is parked as
         * `agent` FIRST — before the constraint narrows. Reversing straight into
         * a constraint violation is how a rollback becomes an outage, and on
         * SQLite the table rebuild would fail outright with rows it cannot
         * re-insert.
         */
        DB::table('users')
            ->whereIn('role', ['coordinator', 'controller'])
            ->update(['role' => 'agent']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', self::PREVIOUS_ROLES)->change();
        });
    }
};
