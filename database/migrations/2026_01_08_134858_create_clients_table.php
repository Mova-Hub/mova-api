<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('avatar')->nullable();
            // $table->string('fcm_token')->nullable(); // Optional: if storing on user record
            $table->rememberToken();
            $table->timestamps();
        });

        // Create the password reset table for clients specifically (optional, but cleaner)
        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('client_fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->string('fcm_token', 255)->unique(); // Ensure token is unique globally
            $table->string('type')->default('fcm'); // fcm | expo
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->useCurrent();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
