<?php

namespace App\Channels;

use App\Models\ClientFcmToken;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Illuminate\Support\Facades\Log;

class FcmChannel
{
    /**
     * Send the given notification.
     */
    public function send($notifiable, Notification $notification): void
    {
        $message = $notification->toFcm($notifiable);

        if (empty($message)) {
            return;
        }

        $tokens = (array) $notifiable->routeNotificationForFcm();

        if (empty($tokens)) {
            return;
        }

        try {
            // Grab Messaging via the App Container to avoid DI crashes
            $messaging = app('firebase.messaging');

            $fcmMessage = CloudMessage::new()
                ->withNotification(FcmNotification::create($message['title'], $message['body']));

            if (isset($message['data'])) {
                $fcmMessage = $fcmMessage->withData($message['data']);
            }

            if (isset($message['android'])) {
                $androidConfig = AndroidConfig::fromArray($message['android']);
                $fcmMessage = $fcmMessage->withAndroidConfig($androidConfig);
            }

            if (isset($message['apns'])) {
                $apnsConfig = ApnsConfig::fromArray($message['apns']);
                $fcmMessage = $fcmMessage->withApnsConfig($apnsConfig);
            }

            $report = $messaging->sendMulticast($fcmMessage, $tokens);

            if ($report->hasFailures()) {
                foreach ($report->failures()->getItems() as $failure) {
                    $errorMsg = $failure->error()->getMessage();
                    $badToken = $failure->target()->value();

                    Log::error("FCM send failed for token {$badToken}: {$errorMsg}");

                    // Auto-delete dead tokens to keep the database clean
                    if (str_contains($errorMsg, 'Requested entity was not found') ||
                        str_contains($errorMsg, 'NotRegistered')) {

                        ClientFcmToken::where('fcm_token', $badToken)->delete();
                        Log::info("Deleted dead FCM token: {$badToken}");
                    }
                }
            }
        } catch (\Throwable $e) {
            // This ensures your API doesn't crash with a 500 if Firebase fails
            Log::error('FCM Notification FATAL Error: ' . $e->getMessage());
        }
    }
}
