<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('plate');
            $table->string('energy_type')->nullable()->after('model');          // diesel | gasoline | electric | hybrid | lpg
            $table->smallInteger('first_registration_year')->unsigned()->nullable()->after('year');
            $table->string('chassis_number')->nullable()->after('first_registration_year');
        });
    }

    public function down(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            $table->dropColumn(['brand', 'energy_type', 'first_registration_year', 'chassis_number']);
        });
    }
};
