<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suspending a customer account.
     *
     * There was no way to stop a client using the app short of deleting them —
     * which destroys their order history, and that history is accounting data.
     * A nullable timestamp instead: reversible, and the reason travels with it
     * so the next agent to look does not have to ask why.
     *
     * Deliberately NOT the `status` enum pattern used on `users`. A timestamp
     * records *when*, which is the question asked in a dispute, and a null
     * check is impossible to get subtly wrong the way a string comparison is.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('phone_verified_at');
            $table->string('blocked_reason')->nullable()->after('blocked_at');

            // The list filters on this constantly once the back-office has it.
            $table->index('blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['blocked_at']);
            $table->dropColumn(['blocked_at', 'blocked_reason']);
        });
    }
};
