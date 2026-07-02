<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('bus_id');
            $table->foreign('bus_id')->references('id')->on('buses')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->nullable(); // carte_grise | assurance | visite_technique | permis | autre
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_kb')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_documents');
    }
};
