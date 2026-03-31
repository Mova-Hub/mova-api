<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Class ExpoChannel
 *
 * Custom notification channel for sending push notifications via Expo (Expo Go / EAS).
 *
 * Tokens must be stored in database with type = 'expo'.
 * Client model should provide a `routeNotificationForExpo()` method returning an array of Expo tokens.
 *
 * Usage:
 * - In Notification class, include `ExpoChannel::class` in the `via()` array if `routeNotificationForExpo()` returns tokens.
 */
class ExpoChannel
{
    /**
     * Expo push API endpoint.
     *
     * @var string
     */
    protected string $expoApiUrl = 'https://exp.host/--/api/v2/push/send';

    /**
     * Send the given notification to the notifiable via Expo.
     *
     * @param  mixed  $notifiable  The entity being notified (e.g. Client model)
     * @param  Notification  $notification  The notification instance
     * @return void
     */
    public function send($notifiable, Notification $notification): void
    {
        // 1. Get Expo tokens from the notifiable
        if (!method_exists($notifiable, 'routeNotificationForExpo')) {
            return;
        }

        $tokens = (array) $notifiable->routeNotificationForExpo();

        if (empty($tokens)) {
            return;
        }

        // 2. Get the notification payload from the Notification class
        if (!method_exists($notification, 'toExpo')) {
            return;
        }

        $message = $notification->toExpo($notifiable);

        if (empty($message)) {
            return;
        }

        // 3. Prepare messages for batch sending
        $messages = [];
        foreach ($tokens as $token) {
            $messages[] = [
                'to' => $token,
                'title' => $message['title'] ?? null,
                'body' => $message['body'] ?? null,
                'data' => $message['data'] ?? [],
                'sound' => $message['sound'] ?? 'default',
            ];
        }

        // 4. Send in batches
        try {
            $response = Http::acceptJson()
                ->post($this->expoApiUrl, $messages);

            if ($response->failed()) {
                Log::error('Expo push failed', [
                    'response' => $response->body(),
                    'tokens' => $tokens,
                    'message' => $message,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Expo push notification error: ' . $e->getMessage(), [
                'tokens' => $tokens,
                'message' => $message,
            ]);
        }
    }
}
