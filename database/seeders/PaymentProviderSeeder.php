<?php

namespace Database\Seeders;

use App\Models\PaymentProvider;
use Illuminate\Database\Seeder;

/**
 * The starting provider set.
 *
 * Everything ships **disabled except cash**, and that is the safe default: an
 * enabled provider with no credentials is a payment method that appears in the
 * app and fails on tap. Ops enables each one from Settings → Paiement after
 * pasting credentials and pressing "Tester".
 *
 * `updateOrCreate` on `code`, so re-seeding refreshes labels and limits without
 * touching credentials or the enabled flag an operator has set. A seeder that
 * resets production configuration is a seeder nobody dares run.
 */
class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->providers() as $provider) {
            $existing = PaymentProvider::where('code', $provider['code'])->first();

            if ($existing) {
                // Presentation and limits refresh; operational state does not.
                $existing->update(collect($provider)
                    ->except(['enabled', 'mode', 'credentials'])
                    ->all());

                continue;
            }

            PaymentProvider::create($provider);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function providers(): array
    {
        return [
            [
                'code' => 'mtn_momo',
                'driver' => 'mtn_momo',
                'label' => 'MTN Mobile Money',
                'description' => 'Confirmation sur votre téléphone',
                'brand_color' => '#FFCC00',
                'enabled' => false,
                'mode' => 'test',
                'fee_percent' => 0.02,
                'fee_bearer' => 'merchant',
                'min_amount' => 100,
                'max_amount' => 2_000_000,
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                // Advisory only. Numbers get ported between operators, so a
                // mismatch WARNS and never blocks — telling a client their own
                // phone number is wrong is worse than a failed attempt.
                'phone_prefixes' => ['06'],
                'fields' => [
                    ['key' => 'phone', 'type' => 'phone', 'label' => 'Numéro à débiter', 'required' => true],
                ],
                'sort_order' => 10,
            ],
            [
                'code' => 'airtel_money',
                'driver' => 'airtel_money',
                'label' => 'Airtel Money',
                'description' => 'Confirmation sur votre téléphone',
                'brand_color' => '#E30613',
                'enabled' => false,
                'mode' => 'test',
                'fee_percent' => 0.02,
                'fee_bearer' => 'merchant',
                'min_amount' => 100,
                'max_amount' => 2_000_000,
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                'phone_prefixes' => ['05', '04'],
                'fields' => [
                    ['key' => 'phone', 'type' => 'phone', 'label' => 'Numéro à débiter', 'required' => true],
                ],
                'sort_order' => 20,
            ],
            [
                'code' => 'mova_credit',
                'driver' => 'mova_credit',
                'label' => 'Solde Mova',
                'description' => 'Votre crédit disponible',
                'brand_color' => '#4CAF50',
                // On by default: it costs nothing, needs no credentials, and
                // hiding a balance the client already holds would be perverse.
                // It still only appears when the balance is non-zero.
                'enabled' => true,
                'mode' => 'live',
                'fee_percent' => 0,
                'min_amount' => 1,
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                'fields' => [],
                'sort_order' => 5,
            ],
            [
                'code' => 'card',
                'driver' => 'card',
                'label' => 'Carte bancaire',
                'description' => 'Visa / Mastercard — pour la diaspora',
                'brand_color' => '#1A1F71',
                // No acquirer contracted. See MOVA-WALLET-AND-PAYMENTS.md §2.
                'enabled' => false,
                'mode' => 'test',
                'fee_percent' => 0.035,
                'fee_bearer' => 'client',
                'min_amount' => 500,
                'currencies' => ['XAF', 'EUR', 'USD'],
                // Empty = anywhere. Cards are the diaspora rail, so restricting
                // them to CG would defeat the point.
                'countries' => [],
                'fields' => [],
                'sort_order' => 30,
            ],
            [
                'code' => 'cash',
                'driver' => 'manual',
                'label' => 'Espèces',
                'description' => 'Réglé auprès de notre équipe',
                'brand_color' => '#64748B',
                'enabled' => true,
                'mode' => 'live',
                'fee_percent' => 0,
                'min_amount' => 0,
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                'fields' => [],
                'sort_order' => 90,
            ],

            /*
             * Back-office only. Not offered in the app — an agent selects these
             * when recording money that arrived outside the system.
             *
             * `mobile_money_manual` is deliberately NOT `mtn_momo`: money a
             * client sent by MoMo and an agent confirmed by hand is a different
             * event from a prompt this system pushed, and merging the two codes
             * would make reconciliation against MTN's statement impossible.
             */
            [
                'code' => 'mobile_money_manual',
                'driver' => 'manual',
                'label' => 'Mobile Money (manuel)',
                'description' => 'Encaissement confirmé par un agent',
                'enabled' => false,
                'mode' => 'live',
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                'sort_order' => 91,
            ],
            [
                'code' => 'bank_transfer',
                'driver' => 'manual',
                'label' => 'Virement bancaire',
                'enabled' => false,
                'mode' => 'live',
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                'sort_order' => 92,
            ],
            [
                'code' => 'cheque',
                'driver' => 'manual',
                'label' => 'Chèque',
                'enabled' => false,
                'mode' => 'live',
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                'sort_order' => 93,
            ],
        ];
    }
}
