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

        // Schema::create('transactions', function (Blueprint $table) {
        //     // Using UUIDs for transactions is a global security standard
        //     $table->uuid('id')->primary();

        //     $table->foreignId('user_id')->constrained()->cascadeOnDelete();

        //     // What is the user paying for? (e.g., 'ticket_purchase', 'wallet_topup')
        //     $table->string('type');

        //     // How are they paying? (e.g., 'mova_wallet', 'mtn_cg', 'airtel_cg')
        //     $table->string('payment_method');

        //     // Optional description for internal use (e.g., "Top-up for John Doe's wallet")
        //     $table->text('description')->nullable();

        //     $table->integer('amount');
        //     $table->string('currency', 3)->default('XAF');

        //     // 'pending', 'successful', 'failed'
        //     $table->string('status')->default('pending');

        //     // The ID returned by MTN/Airtel (nullable because the wallet won't have one)
        //     $table->string('provider_reference')->nullable()->unique();

        //     // Polymorphic relation: Links this transaction to a specific item (like a Bus Ticket ID)
        //     $table->nullableUuidMorphs('payable');

        //     $table->timestamps();
        // });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
