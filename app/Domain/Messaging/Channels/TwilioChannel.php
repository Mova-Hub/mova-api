<?php

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Contracts\MessagingChannel;
use App\Domain\Messaging\DTOs\SendResult;
use App\Domain\Settings\Facades\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Twilio — the second adapter.
 *
 * Replaces App\Services\SmsService, which was constructor-wired to Twilio and
 * logged a warning on every boot when credentials were missing. Kept as an
 * option rather than deleted: Twilio is a genuine fallback if Infobip's Central
 * African routing disappoints, and having two adapters is what proves the
 * registry abstraction actually holds.
 *
 * Uses the REST API over HTTP rather than the `twilio/sdk` package. The SDK
 * pulls a large dependency tree for two endpoints, and — more to the point — it
 * throws on construction when credentials are absent, which is the default
 * state in development.
 */
class TwilioChannel implements MessagingChannel
{
    private const BASE = 'https://api.twilio.com/2010-04-01';

    public function supports(string $kind): bool
    {
        return in_array($kind, ['sms', 'whatsapp'], true);
    }

    public function send(string $to, string $kind, string $body, array $variables = []): SendResult
    {
        $sid = $this->credential('account_sid');
        $token = $this->credential('auth_token');

        if (! $sid || ! $token) {
            return SendResult::failed($kind, 'Twilio non configuré.', retryable: false);
        }

        $from = $kind === 'whatsapp'
            ? 'whatsapp:' . $this->credential('whatsapp_from')
            : $this->credential('from');

        if (! $from || $from === 'whatsapp:') {
            return SendResult::failed($kind, "Numéro d’envoi Twilio manquant pour {$kind}.", retryable: false);
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->withBasicAuth($sid, $token)
                ->post(self::BASE . "/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To' => $kind === 'whatsapp' ? 'whatsapp:' . $to : $to,
                    'Body' => $body,
                ]);
        } catch (Throwable $e) {
            return SendResult::failed($kind, $e->getMessage());
        }

        if ($response->successful()) {
            return SendResult::sent($kind, $response->json('sid'));
        }

        /*
         * 21211 is Twilio's "invalid To number". Non-retryable for the same
         * reason as Infobip's 400 — every channel will reject it identically,
         * so walking the chain only wastes rate limit on a typo.
         */
        $code = (int) $response->json('code', 0);

        return SendResult::failed(
            $kind,
            'Twilio ' . $code . ': ' . (string) $response->json('message', ''),
            retryable: ! in_array($code, [21211, 21614, 21408], true),
        );
    }

    public function healthCheck(array $credentials): SendResult
    {
        $sid = (string) ($credentials['account_sid'] ?? '');
        $token = (string) ($credentials['auth_token'] ?? '');

        if (! $sid || ! $token) {
            return SendResult::failed('twilio', 'Account SID et Auth Token requis.', false);
        }

        try {
            // Fetches the account itself — authenticated, and sends nothing.
            $response = Http::timeout(15)
                ->withBasicAuth($sid, $token)
                ->get(self::BASE . "/Accounts/{$sid}.json");
        } catch (Throwable $e) {
            return SendResult::failed('twilio', $e->getMessage());
        }

        return $response->successful()
            ? SendResult::sent('twilio', 'Compte : ' . $response->json('friendly_name'))
            : SendResult::failed('twilio', $response->status() === 401
                ? 'Identifiants refusés.'
                : 'Twilio a répondu ' . $response->status());
    }

    private function credential(string $key): ?string
    {
        $value = Settings::get("notifications.twilio_{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
