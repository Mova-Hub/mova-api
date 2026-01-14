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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('method', ['cash', 'mobile_money', 'bank_transfer', 'check'])->default('mobile_money');
            $table->string('reference')->nullable();  // External ID (M-Pesa code, Check number, etc.)
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
