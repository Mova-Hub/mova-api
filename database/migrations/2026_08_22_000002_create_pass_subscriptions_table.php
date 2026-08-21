<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client's entitlement to travel over a period.
     *
     * The subscription — not the card — is the authority on whether someone may
     * board. That resolves PRD open decision D2 in favour of option (b), the
     * server snapshot: renewing extends this row, and Mova Control validates
     * against a downloaded snapshot of it. Otherwise renewal online would leave
     * the chip's signed expiry stale and every subscriber would have to
     * remember to re-tap their card against their phone after paying.
     *
     * The card's signed payload is kept as the offline fallback for subscribers
     * issued since the last snapshot.
     *
     * Plan values are COPIED here at purchase (`price_paid`, `trips_remaining`)
     * rather than read through the relation. A plan whose price changes must
     * not retroactively rewrite what somebody already paid.
     */
    public function up(): void
    {
        Schema::create('pass_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // Restrict, not cascade: deleting a plan must never silently erase
            // the purchase history that references it. Plans soft-delete.
            $table->foreignId('pass_plan_id')->constrained('pass_plans')->restrictOnDelete();

            $table->string('status')->default('pending');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // NULL = unlimited, mirroring the plan at purchase time.
            $table->unsignedInteger('trips_remaining')->nullable();

            $table->unsignedInteger('price_paid')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->boolean('auto_renew')->default(false);

            // The signed snapshot of this entitlement, for offline verification.
            $table->string('key_id')->nullable();
            $table->string('signature')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            // "Is this client entitled right now?" — the hottest query here.
            $table->index(['client_id', 'status', 'expires_at']);
            // The nightly sweep that moves lapsed rows to `expired`.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_subscriptions');
    }
};
