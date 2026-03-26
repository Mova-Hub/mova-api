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
        Schema::create('emplois', function (Blueprint $table) {
            $table->uuid('id')->primary(); // Sécurité standard avec UUID

            $table->string('title');
            $table->string('department');
            $table->string('location');
            $table->string('country')->default('cg');

            $table->string('work_mode'); // onsite, hybrid, remote
            $table->string('contract_type'); // full_time, part_time, cdi, etc.
            $table->string('status')->default('draft'); // draft, open, closed

            $table->text('short_desc')->nullable();

            // Les colonnes JSON pour stocker nos fameux tableaux (1 ligne = 1 point)
            $table->json('responsibilities')->nullable();
            $table->json('requirements')->nullable();
            $table->json('benefits')->nullable();

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emplois');
    }
};
