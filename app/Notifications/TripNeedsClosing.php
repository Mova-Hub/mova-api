<?php

namespace App\Notifications;

use App\Channels\ExpoChannel;
use App\Channels\FcmChannel;
use App\Models\Client;
use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * This trip has passed its end time and is still running.
 *
 * Sent on the day the trip should have finished, to BOTH sides, and it is the
 * grace period made visible. A coordinator who forgot to press "Terminer"
 * leaves the reservation `in_progress` for ever: `started_at` and `completed_at`
 * are only ever written by a human, so nothing else would notice.
 *
 * **Addressed to two different models**, which is why this does not use the
 * `NotifiesClient` trait. The client gets a courtesy note, staff get a task.
 * `via()` branches on the notifiable rather than assuming a `Client`, for the
 * same reason `routes/channels.php` has to: Sanctum resolves either model and a
 * callback that assumes one is a hole.
 *
 * It fires once. `reservations.closing_notified_at` is stamped by the sweep, so
 * the reminder does not repeat every night through the grace period. A nag that
 * arrives daily is a nag people filter.
 */
class TripNeedsClosing extends Notification
{
    use Queueable;

    public function __construct(public Reservation $reservation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'routeNotificationForFcm')
            && ! empty($notifiable->routeNotificationForFcm())) {
            $channels[] = FcmChannel::class;
        }

        if (method_exists($notifiable, 'routeNotificationForExpo')
            && ! empty($notifiable->routeNotificationForExpo())) {
            $channels[] = ExpoChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $route = $this->route();

        if ($this->isClient($notifiable)) {
            return (new MailMessage)
                ->subject('Votre trajet Mova est arrivé à son terme')
                ->greeting('Bonjour,')
                ->line('Votre trajet '.$route.' est arrivé à l\'heure de fin prévue.')
                ->line('Si tout s\'est bien passé, aucune action n\'est nécessaire de votre part.')
                ->line('Si quelque chose ne va pas, répondez à ce message ou contactez-nous.');
        }

        return (new MailMessage)
            ->subject('Trajet à clôturer : '.($this->reservation->code ?: $route))
            ->greeting('Bonjour,')
            ->line('Ce trajet a dépassé son heure de fin et est toujours marqué « en cours ».')
            ->line('Trajet : '.$route)
            ->line('Coordinateur : '.($this->reservation->coordinator?->name ?: 'non assigné'))
            ->line('Sans clôture manuelle, il sera fermé automatiquement demain.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $body = $this->body($notifiable);

        return [
            'type' => 'trip_needs_closing',
            'reservation_id' => $this->reservation->id,
            'order_id' => $this->reservation->order_id,
            'code' => $this->reservation->code,
            'title' => $this->title($notifiable),
            // Both keys: the in-app inbox reads `message`, the existing
            // notifications emit `body`. See TripExpired for the full note.
            'message' => $body,
            'body' => $body,
            'route' => '/trip/'.$this->reservation->order_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toExpo(object $notifiable): array
    {
        return $this->push($notifiable) + ['sound' => 'default'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        return $this->push($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    private function push(object $notifiable): array
    {
        return [
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'data' => [
                'type' => 'trip_needs_closing',
                'order_id' => (string) $this->reservation->order_id,
                'route' => '/trip/'.$this->reservation->order_id,
            ],
        ];
    }

    private function isClient(object $notifiable): bool
    {
        return $notifiable instanceof Client;
    }

    private function title(object $notifiable): string
    {
        return $this->isClient($notifiable) ? 'Trajet terminé ?' : 'Trajet à clôturer';
    }

    private function body(object $notifiable): string
    {
        $route = $this->route();

        return $this->isClient($notifiable)
            ? 'Votre trajet '.$route.' est arrivé à son terme.'
            : 'Le trajet '.$route.' est toujours en cours après son heure de fin.';
    }

    private function route(): string
    {
        return $this->reservation->from_location.' vers '.$this->reservation->to_location;
    }
}
