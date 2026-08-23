<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff 2FA, and a record of the last sign-in.
     *
     * `AuthController::toggleTwoFA()` has always guarded on
     * `array_key_exists('is_2fa_enabled', $user->getAttributes())` — and that
     * column exists on `clients` but never existed on `users`. So the check was
     * permanently false: the endpoint returned success, discarded the request,
     * and reported 2FA as off forever, while `MyAccount.tsx` shipped a toggle
     * that appeared to work.
     *
     * `last_login_at` is added alongside it because the back-office needs to be
     * able to answer "when did this account last sign in?" — the first question
     * asked about a suspicious account, and one nothing in the schema could
     * answer.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_2fa_enabled')->default(false)->after('status');
            $table->timestamp('last_login_at')->nullable()->after('is_2fa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_2fa_enabled', 'last_login_at']);
        });
    }
};
