<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Always store money as integers (lowest denomination) to avoid decimal math errors!
            // e.g., 5000 FCFA is stored as 5000.
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
