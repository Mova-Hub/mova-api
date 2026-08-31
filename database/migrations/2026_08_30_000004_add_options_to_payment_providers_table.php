<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One provider that is really several choices.
 *
 * Every provider so far has been a single rail: MTN is MTN, Airtel is Airtel,
 * and "which provider" and "which thing the customer taps" were the same
 * question. Yabetoo breaks that. It is an aggregator sitting in front of both
 * MTN MoMo and Airtel Money, so one row, one set of credentials and one driver
 * have to present two choices to the customer.
 *
 * The alternative was two provider rows sharing a driver (`yabetoo_mtn`,
 * `yabetoo_airtel`). Rejected: it duplicates the credentials, doubles what has
 * to be tested and enabled in the back office, and makes the fee configuration
 * two places that can disagree about the same contract.
 *
 * So a provider may carry `options`, each of which is what the customer
 * actually sees:
 *
 *   [{ "code": "mtn", "label": "MTN MoMo", "logo_path": null,
 *      "brand_color": "#FFCC00", "phone_prefixes": ["06"] }, ...]
 *
 * Null or empty means what it has always meant: this provider is one choice,
 * and every existing row keeps behaving exactly as before. That is the whole
 * reason this is a nullable column rather than a new table.
 *
 * `code` is what gets sent back as the operator on a charge, so it must match
 * the vocabulary the provider's own API expects (`mtn` / `airtel` for Yabetoo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payment_providers', 'options')) {
            return;
        }

        Schema::table('payment_providers', function (Blueprint $table) {
            $table->json('options')->nullable()->after('fields');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payment_providers', 'options')) {
            return;
        }

        Schema::table('payment_providers', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
