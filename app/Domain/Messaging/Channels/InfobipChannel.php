<?php

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Contracts\MessagingChannel;
use App\Domain\Messaging\DTOs\SendResult;
use App\Domain\Settings\Facades\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Infobip — SMS and WhatsApp through one API.
 *
 * Chosen over Twilio for Congo-Brazzaville: direct MNO connections in Central
 * Africa mean OTPs land rather than being routed through aggregator hops, and
 * one account covers both channels. It is also an official WhatsApp BSP, so the
 * failover chain does not need a second vendor relationship.
 *
 * Two things to know about the WhatsApp side:
 *
 *  1. **Outside a 24-hour customer service window, WhatsApp only permits
 *     pre-approved TEMPLATES.** Free text is rejected. Mova's messages are
 *     almost all business-initiated (OTP, payment confirmed, reminder), so the
 *     template path is the normal path, not the exception.
 *  2. Template names and their placeholder order are configured in Infobip's
 *     console, not here. `variables` is positional against that registration.
 */
class InfobipChannel implements MessagingChannel
{
    public function supports(string $kind): bool
    {
        return in_array($kind, ['sms', 'whatsapp'], true);
    }

    public function send(string $to, string $kind, string $body, array $variables = []): SendResult
    {
        $baseUrl = $this->baseUrl();
        $apiKey = $this->credential('api_key');

        if (! $baseUrl || ! $apiKey) {
            return SendResult::failed($kind, 'Infobip non configuré.', retryable: false);
        }

        try {
            $response = $kind === 'whatsapp'
                ? $this->sendWhatsApp($baseUrl, $apiKey, $to, $body, $variables)
                : $this->sendSms($baseUrl, $apiKey, $to, $body);
        } catch (Throwable $e) {
            return SendResult::failed($kind, $e->getMessage());
        }

        if ($response->successful()) {
            return SendResult::sent($kind, $response->json('messages.0.messageId'));
        }

        /*
         * 400 means Infobip rejected the request itself — almost always a
         * malformed destination. Marked non-retryable so the chain stops
         * instead of putting the same bad number through SMS and push.
         */
        return SendResult::failed(
            $kind,
            'Infobip ' . $response->status() . ': ' . (string) $response->json('requestError.serviceException.text', ''),
            retryable: $response->status() !== 400,
        );
    }

    private function sendSms(string $baseUrl, string $apiKey, string $to, string $body)
    {
        return $this->client($baseUrl, $apiKey)->post('/sms/2/text/advanced', [
            'messages' => [[
                'from' => Settings::string('notifications.sms_sender_id', 'Mova'),
                // Infobip wants the number WITHOUT the leading +.
                'destinations' => [['to' => ltrim($to, '+')]],
                'text' => $body,
            ]],
        ]);
    }

    private function sendWhatsApp(string $baseUrl, string $apiKey, string $to, string $body, array $variables)
    {
        $sender = $this->credential('whatsapp_sender');

        // A template name in `variables` means a business-initiated message;
        // its absence means we are inside a 24h window and free text is legal.
        $template = $variables['_template'] ?? null;

        if ($template) {
            unset($variables['_template']);

            return $this->client($baseUrl, $apiKey)->post('/whatsapp/1/message/template', [
                'messages' => [[
                    'from' => $sender,
                    'to' => ltrim($to, '+'),
                    'content' => [
                        'templateName' => $template,
                        // Positional against the template's registration in
                        // Infobip's console — order matters more than keys.
                        'templateData' => ['body' => ['placeholders' => array_values($variables)]],
                        'language' => $this->credential('whatsapp_language', 'fr') ?? 'fr',
                    ],
                ]],
            ]);
        }

        return $this->client($baseUrl, $apiKey)->post('/whatsapp/1/message/text', [
            'from' => $sender,
            'to' => ltrim($to, '+'),
            'content' => ['text' => $body],
        ]);
    }

    public function healthCheck(array $credentials): SendResult
    {
        $baseUrl = rtrim((string) ($credentials['base_url'] ?? ''), '/');
        $apiKey = (string) ($credentials['api_key'] ?? '');

        if (! $baseUrl || ! $apiKey) {
            return SendResult::failed('infobip', 'Base URL et clé API requises.', false);
        }

        try {
            // Reads the account balance: authenticated, cheap, and sends
            // nothing — a health check that texts someone is not a health check.
            $response = $this->client($baseUrl, $apiKey)->get('/account/1/balance');
        } catch (Throwable $e) {
            return SendResult::failed('infobip', $e->getMessage());
        }

        return $response->successful()
            ? SendResult::sent('infobip', 'Solde : ' . $response->json('balance') . ' ' . $response->json('currency'))
            : SendResult::failed('infobip', $response->status() === 401
                ? 'Clé API refusée.'
                : 'Infobip a répondu ' . $response->status());
    }

    private function client(string $baseUrl, string $apiKey)
    {
        return Http::baseUrl($baseUrl)
            ->timeout(15)
            ->withHeaders(['Authorization' => 'App ' . $apiKey])
            ->acceptJson()
            ->asJson();
    }

    private function baseUrl(): ?string
    {
        // Infobip issues a PER-ACCOUNT base URL. There is no shared host, so
        // this is a credential rather than a constant.
        $url = $this->credential('base_url');

        return $url ? rtrim($url, '/') : null;
    }

    private function credential(string $key, ?string $default = null): ?string
    {
        $value = Settings::get("notifications.infobip_{$key}", $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
