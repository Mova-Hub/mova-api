<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One physical chip.
     *
     * A subscriber may hold several over time (lost, damaged, replaced), so
     * this is a history, not a single current card — `status` and
     * `replaced_by_id` carry which is which.
     *
     * The blacklist PRD §6 modelled as its own table lives here instead, as
     * `status = blocked` plus a reason. Two tables that must agree about
     * whether a card is usable is one table too many: the moment they diverge,
     * one of them is telling an inspector the wrong thing. The downloadable
     * blacklist is a query over this column.
     */
    public function up(): void
    {
        Schema::create('pass_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Null until a subscriber claims it. A card leaves the counter
            // ENCODED and unowned, which is what makes a stolen blank batch
            // worthless (PRD §5.1).
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // The chip's factory UID. Unique across the fleet, and the key
            // Mova Control looks up offline.
            $table->string('chip_uid')->unique();

            // Printed on the card for the no-NFC / iOS-declined-sheet path
            // (PA-2). This is an ACTIVATION CREDENTIAL, so it is random and
            // long — see config/pass.php.
            $table->string('printed_serial')->unique();

            $table->string('status')->default('encoded');

            // What was actually written to the chip, so a card can be
            // re-verified or re-encoded without guessing.
            $table->string('key_id')->nullable();
            $table->string('signature')->nullable();
            $table->timestamp('entitlement_expires_at')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_reason')->nullable(); // lost|stolen|fraud|suspended
            $table->foreignId('replaced_by_id')->nullable()->constrained('pass_cards')->nullOnDelete();

            // Last time this chip was seen by any reader. Drives "your card was
            // last used on…" and flags cards that stopped appearing.
            $table->timestamp('last_seen_at')->nullable();

            // Which staff member encoded it. Audit trail for the counter.
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            // The blacklist export: blocked cards, newest first.
            $table->index(['status', 'blocked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_cards');
    }
};
