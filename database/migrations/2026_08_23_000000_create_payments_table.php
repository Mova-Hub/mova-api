<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money movements against an order.
     *
     * A table, not a column on `orders`, because a payment has a life of its
     * own: it is attempted, it fails, it is retried on another number, it
     * eventually succeeds, and months later it may be refunded. Collapsing that
     * into `orders.paid_at` throws away every attempt but the last — which is
     * exactly the history support needs when a client says they were debited.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('provider');   // mtn_momo | airtel_money | cash
            $table->string('status')->default('pending');

            /*
             * Whole francs, as an integer. XAF has no subunit — the smallest
             * coin is one franc — so a decimal here would only invite the
             * rounding errors money-as-float is famous for, for a fractional
             * part that cannot exist.
             */
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('XAF');

            // The number the prompt was pushed to. May differ from the account
            // phone — people pay from a spouse's or an employer's wallet.
            $table->string('payer_phone')->nullable();

            // The provider's own id. Unique so a replayed webhook cannot create
            // a second record for one transaction.
            $table->string('provider_reference')->nullable()->unique();
            $table->text('failure_reason')->nullable();

            $table->timestamp('processing_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Raw provider payloads, kept for reconciliation disputes.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
