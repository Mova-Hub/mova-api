<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->json('address')->nullable()->after('phone'); // {street, quartier, arrondissement, city, department, country}
            $table->date('permit_expiration_date')->nullable()->after('license_no');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'address', 'permit_expiration_date']);
        });
    }
};
