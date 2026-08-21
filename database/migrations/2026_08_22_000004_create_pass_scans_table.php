<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every scan, accepted or refused, from any source.
     *
     * Refusals are the valuable rows. An accepted scan says the system worked;
     * a run of INVALID verdicts on one line at one hour is how forged cards get
     * noticed, and it is the only signal available for the cloning risk PRD
     * §4.3 accepts rather than prevents — the same subscriber appearing on two
     * buses at once shows up here or nowhere.
     */
    public function up(): void
    {
        Schema::create('pass_scans', function (Blueprint $table) {
            $table->id();

            /*
             * Idempotency key, generated on the device.
             *
             * Mova Control uploads logs in bulk after hours offline, and those
             * uploads WILL be retried on a flaky connection. Without a unique
             * key per scan, one retry doubles a shift's boarding figures.
             * Criterion A6 is exactly this.
             */
            $table->uuid('client_reference')->nullable()->unique();

            // Nullable: an unrecognised card still produces a usable record.
            $table->foreignId('pass_card_id')->nullable()->constrained('pass_cards')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pass_subscription_id')->nullable()
                ->constrained('pass_subscriptions')->nullOnDelete();

            // Denormalised ON PURPOSE — kept even when no card matches, which
            // is the case that matters most for fraud analysis.
            $table->string('chip_uid')->nullable();

            $table->string('source')->default('control');  // app|control|counter
            $table->string('verdict');                     // accepted|expired|blocked|invalid|unknown
            $table->string('reason')->nullable();

            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('bus_line')->nullable();
            $table->string('device_id')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Device clock, which is not trustworthy — see PRD §4.4. Kept
            // alongside the server's own timestamps so a manipulated device
            // shows up as a discrepancy rather than as clean data.
            $table->timestamp('scanned_at');
            $table->timestamp('synced_at')->nullable();
            $table->unsignedInteger('offline_duration_minutes')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['chip_uid', 'scanned_at']);
            $table->index(['client_id', 'scanned_at']);
            // Fraud sweeps: refusals within a window.
            $table->index(['verdict', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_scans');
    }
};
