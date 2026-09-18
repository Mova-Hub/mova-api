<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\NotifiesClient;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * How was your trip?
 *
 * The second notification a passenger gets around a completed journey. The first
 * one, if the trip needed it, said the trip looked finished; this one asks what
 * they thought.
 *
 * Sent by a sweep rather than from whichever controller marked the trip
 * complete, because there are two completion paths, the field app and the back
 * office, and a dispatch wired into one of them would silently miss every trip
 * closed by the other.
 *
 * It deliberately never reaches a trip that `trips:sweep` closed. Asking
 * somebody to rate a journey the system gave up on invites a one-star answer
 * about a trip that may have gone perfectly well, and that score would land on a
 * coordinator's average.
 */
class RateYourTrip extends Notification
{
    use NotifiesClient, Queueable;

    public function __construct(public Order $order) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Comment s\'est passé votre trajet ?')
            ->greeting('Bonjour '.($this->order->contact_name ?: '').',')
            ->line('Votre trajet '.$this->route().' est terminé.')
            ->line('Votre avis nous aide à améliorer le service et à reconnaître le travail de nos coordinateurs.')
            ->action('Noter mon trajet', $this->deepLink())
            ->line('Cela prend moins d\'une minute.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'rate_your_trip',
            'order_id' => $this->order->id,
            'code' => $this->order->reservation?->code,
            'title' => $this->title(),
            /*
             * `message` AND `body`.
             *
             * The in-app inbox reads `message` while every older notification
             * emits `body`. The controller now falls back, and sending both
             * means this one renders correctly against either version.
             */
            'message' => $this->body(),
            'body' => $this->body(),
            // Tapping opens the rating screen directly rather than the trip.
            'route' => '/rate/'.$this->order->id,
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
            'body' => $this->body(),
            'data' => [
                'type' => 'rate_your_trip',
                'order_id' => (string) $this->order->id,
                'route' => '/rate/'.$this->order->id,
            ],
        ];
    }

    private function title(): string
    {
        return 'Notez votre trajet';
    }

    private function body(): string
    {
        return 'Comment s\'est passé votre trajet '.$this->route().' ?';
    }

    private function route(): string
    {
        return $this->order->origin.' vers '.$this->order->destination;
    }

    private function deepLink(): string
    {
        return rtrim((string) config('app.url'), '/').'/rate/'.$this->order->id;
    }
}
