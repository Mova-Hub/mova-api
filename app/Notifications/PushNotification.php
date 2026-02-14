<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidFcmOptions;
use NotificationChannels\Fcm\Resources\AndroidNotification;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class PushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private $title;
    private $body;
    private $data; // Optional custom data payload

    public function __construct(string $title, string $body, array $data = [])
    {
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
    }

    public function via($notifiable)
    {
        return [FcmChannel::class];
    }

    public function toFcm($notifiable): FcmMessage
    {
        return (new FcmMessage())
            ->notification(
                (new FcmNotification())
                    ->title($this->title)
                    ->body($this->body)
                    // ->image('https://example.com/icon.png') // Optional
            )
            ->data($this->data) // e.g., ['key' => 'value'] for app handling
            ->android(
                (new AndroidConfig())
                    ->ttl('86400s') // 1 day
                    ->channelId('default-channel') // Match your React Native channel
                    ->notification(
                        (new AndroidNotification())
                            ->sound('default')
                            ->color('#FF0000') // Optional
                    )
                    ->options(AndroidFcmOptions::create()->analyticsLabel('analytics'))
            );
            // Add iOS config if needed: ->apns( ... )
    }
}
