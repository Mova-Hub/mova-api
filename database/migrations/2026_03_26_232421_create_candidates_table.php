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
        Schema::create('candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Clé étrangère vers l'offre d'emploi
            $table->foreignUuid('emploi_id')->constrained('emplois')->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('linkedin_profile')->nullable();

            $table->string('resume_path')->nullable(); // Lien vers le PDF du CV
            $table->string('cover_letter_path')->nullable(); // Lien vers la lettre

            $table->string('status')->default('pending'); // pending, reviewed, accepted, rejected
            $table->text('notes')->nullable(); // Notes internes des RH

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
