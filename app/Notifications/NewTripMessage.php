<?php

namespace App\Notifications;

use App\Models\TripMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A message arrived on a trip, and the recipient is not looking at the app.
 *
 * **Push and the in-app inbox only. Deliberately no mail.** A chat message is
 * a real-time thing; an e-mail arriving twenty minutes later, about a
 * conversation that has moved on, is noise that trains people to ignore the
 * sender. `NotifiesClient` is therefore NOT used here, because its whole job is
 * to add mail whenever an address exists.
 *
 * Sent to whichever side did not write the message. The web socket already
 * delivers it to anyone with the screen open, so this exists purely for the
 * case where the app is closed.
 */
class NewTripMessage extends Notification
{
    use Queueable;

    public function __construct(public TripMessage $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (method_exists($notifiable, 'routeNotificationForFcm')
            && ! empty($notifiable->routeNotificationForFcm())) {
            $channels[] = \App\Channels\FcmChannel::class;
        }

        if (method_exists($notifiable, 'routeNotificationForExpo')
            && ! empty($notifiable->routeNotificationForExpo())) {
            $channels[] = \App\Channels\ExpoChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trip_message',
            'message_id' => $this->message->id,
            'reservation_id' => $this->message->reservation_id,
            'order_id' => $this->message->reservation?->order_id,
            'title' => $this->title(),
            'body' => $this->preview(),
            'route' => $this->route(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toExpo(object $notifiable): array
    {
        return $this->push() + ['sound' => 'default'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        return $this->push();
    }

    /**
     * @return array<string, mixed>
     */
    private function push(): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->preview(),
            'data' => [
                'type' => 'trip_message',
                'reservation_id' => (string) $this->message->reservation_id,
                'order_id' => (string) ($this->message->reservation?->order_id ?? ''),
                'route' => $this->route(),
            ],
        ];
    }

    private function title(): string
    {
        // The sender's name, because a notification saying only "Nouveau
        // message" makes people open the app to find out who it was from.
        return $this->message->sender?->name ?: 'Nouveau message';
    }

    /**
     * The message itself, truncated.
     *
     * A lock screen shows one or two lines whatever we send, so the truncation
     * is ours rather than the operating system's, and ends on a word.
     */
    private function preview(): string
    {
        $body = trim($this->message->body);

        return mb_strlen($body) > 120
            ? rtrim(mb_substr($body, 0, 117)).'...'
            : $body;
    }

    /**
     * Where tapping the notification lands.
     *
     * These are the app's OWN route paths and have to match its router exactly,
     * which is why they are flat rather than nested: expo-router has
     * `app/(app)/trip/[id].tsx` already, so a sibling `trip/[id]/messages`
     * would collide with it. The conversation therefore lives at
     * `/messages/{orderId}`, and a path invented to look tidier here would
     * simply open nothing.
     */
    private function route(): string
    {
        $orderId = $this->message->reservation?->order_id;

        return $orderId ? "/messages/{$orderId}" : '/trips';
    }
}
