<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Default business rules.
 *
 * The system runs correctly with an EMPTY settings table — SettingsRepository
 * falls back to config, and every `Settings::x()` call names its own default.
 * This seeder exists so the Settings page opens with real values in the fields
 * rather than a grid of blanks that an operator has to guess at.
 *
 * Only inserts what is missing. Never overwrites: re-running the seeders after
 * an operator has tuned the deposit percentage must not silently put it back.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $group => $values) {
            foreach ($values as $key => $value) {
                Setting::firstOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $value, 'is_secret' => false],
                );
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function defaults(): array
    {
        return [
            'general' => [
                'company_name' => 'Mova Mobility',
                'legal_name' => 'Mova Mobility SARL',
                'support_email' => 'contact@mova-mobility.com',
                'support_phone' => '+242 06 000 00 00',
                'address' => 'Brazzaville, République du Congo',
                'country' => 'CG',
                'currency' => 'XAF',
                'timezone' => 'Africa/Brazzaville',
            ],

            'rules' => [
                /*
                 * Down payment. 30% is enough to cover a cancelled charter's
                 * repositioning cost without asking a family to find the whole
                 * fare before the trip exists.
                 */
                'allow_deposit' => true,
                'deposit_percent' => 0.3,

                // Below this, a deposit is more friction than it is worth —
                // two payments to collect 15 000 F helps nobody.
                'deposit_min_amount' => 50_000,

                'refund_window_days' => 7,
                'cancellation_fee_percent' => 0.1,

                // How long a client has to settle a confirmed order before the
                // reminder chain starts. See `payments:remind`.
                'payment_due_days' => 3,
            ],

            'wallet' => [
                /*
                 * Closed-loop. There is no `allow_top_up` key, and adding one
                 * would not enable anything — WalletService has no method to
                 * flag. See MOVA-WALLET-AND-PAYMENTS.md §3.3.
                 */
                'enabled' => true,

                // Credit may cover a whole payment by default. Lower this if
                // promotional credit ever gets generous enough to be worth
                // farming.
                'max_share_of_payment' => 1.0,

                // Ceiling on one manual grant, so a mistyped promo is a
                // nuisance rather than an incident.
                'max_manual_grant' => 500_000,

                'promo_expiry_days' => 90,
                'allow_for_subscriptions' => true,
            ],

            'notifications' => [
                'channel_provider' => 'log',
                'whatsapp_enabled' => false,
                'sms_enabled' => false,
                // WhatsApp first where available, SMS as the fallback. An OTP
                // that does not arrive is an account nobody can create.
                'otp_chain' => ['whatsapp', 'sms'],
                'payment_chain' => ['whatsapp', 'sms', 'push'],
                'reminders_enabled' => true,
            ],

            'billing' => [
                'invoice_prefix' => 'MOVA',
                'quote_prefix' => 'DEV',
                'legal_mentions' => 'Mova Mobility SARL — RCCM CG-BZV-…  ·  NIU …',
                'footer_note' => 'Merci de votre confiance.',
                'show_logo' => true,
            ],
        ];
    }
}
