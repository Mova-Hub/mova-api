<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per payment method offered to clients.
     *
     * This table is half of the "add a provider without a deploy" promise. The
     * other half is a class implementing PaymentDriver, named by `driver` and
     * resolved through config/payment.php's map. Everything that varies between
     * two MTN-shaped providers — label, logo, credentials, fees, limits — is a
     * column here rather than a constant in PHP.
     *
     * The mobile app reads its whole method list from this table, logo and
     * label included, so enabling a provider changes the app with no release.
     */
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();

            /** Stable machine name — `payments.provider_code` points at this. */
            $table->string('code')->unique();

            /*
             * The driver key, looked up in config/payment.php.
             *
             * Separate from `code` on purpose: two rows can share a driver.
             * "MTN MoMo Congo" and "MTN MoMo Cameroun" are one class and two
             * sets of credentials, and a shop wanting a second Airtel merchant
             * account should not need a second class.
             */
            $table->string('driver');

            $table->string('label');
            $table->string('description')->nullable();
            /** Relative to the public disk. Served through /storage. */
            $table->string('logo_path')->nullable();
            /** Brand hex, so the app tints a row without shipping a palette. */
            $table->string('brand_color', 7)->nullable();

            $table->boolean('enabled')->default(false);
            /** test | live — selects the driver's base URL. */
            $table->string('mode')->default('test');

            /*
             * Encrypted at rest (see the model's `encrypted:array` cast) and
             * never returned by a read endpoint — the API answers
             * `has_credentials: true` plus a masked tail instead.
             */
            $table->text('credentials')->nullable();

            /*
             * Fees, recorded per provider because they differ and because the
             * client is entitled to see them before tapping.
             *
             * NOTE: config/pricing.php already carries
             * mobile_money_client_percent (0.04) inside the quote. These two
             * must not disagree — see MOVA-WALLET-AND-PAYMENTS.md §4.4.
             */
            $table->decimal('fee_percent', 6, 4)->default(0);
            $table->unsignedInteger('fee_fixed')->default(0);
            /** client | merchant — who the fee is added to. */
            $table->string('fee_bearer')->default('merchant');

            $table->unsignedInteger('min_amount')->default(0);
            $table->unsignedInteger('max_amount')->nullable();

            /** ["XAF"] — a provider offered outside its currency is a failure. */
            $table->json('currencies')->nullable();
            /** ["CG"] — ISO-2, for filtering by the client's country. */
            $table->json('countries')->nullable();
            /** ["06"] — advisory prefixes. NEVER used to block a payment. */
            $table->json('phone_prefixes')->nullable();

            /*
             * What the app must collect before charging, as a descriptor:
             * [{"key":"phone","type":"phone","label":"Numéro à débiter"}].
             *
             * Rendered generically by the payment sheet, so a provider needing
             * an account number instead of a phone works without an app release.
             */
            $table->json('fields')->nullable();

            /** collect | refund | status_poll | webhook — reported by the driver. */
            $table->json('capabilities')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status')->nullable();

            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
