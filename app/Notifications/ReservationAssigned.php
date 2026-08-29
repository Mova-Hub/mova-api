<?php

namespace App\Notifications;

use App\Channels\ExpoChannel;
use App\Channels\FcmChannel;
use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You are running this trip."
 *
 * Sent to a coordinator the moment a reservation becomes theirs, and — with
 * `$released` — to the person it was taken from. Both halves matter: an
 * assignment nobody was told about is the same as no assignment, and a
 * reassignment nobody was told about means two people show up or nobody does.
 *
 * Carries what someone needs to act on it from a phone at six in the morning:
 * the code, the route, when, how many vehicles, and the client's number. The
 * client's PHONE specifically — a coordinator's first action is to call them,
 * and making them open the back-office to find it defeats the point.
 *
 * Queued, like `ReservationStatusUpdated`, so a slow mail server never holds up
 * the conversion request that triggered it.
 */
class ReservationAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [60, 300, 600];

    public function __construct(
        protected Reservation $reservation,
        /** True when this tells someone the mission is no longer theirs. */
        protected bool $released = false,
    ) {}

    /**
     * Database always; mail and push where the account can receive them.
     *
     * Mirrors `ReservationStatusUpdated::via()`, minus its duplicated FCM block
     * — that file adds `FcmChannel` twice, which sends every push twice.
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
        $r = $this->reservation;

        if ($this->released) {
            return (new MailMessage)
                ->subject("Mission retirée — {$r->code}")
                ->greeting("Bonjour {$notifiable->name},")
                ->line("La mission **{$r->code}** ({$r->from_location} → {$r->to_location}) a été confiée à quelqu’un d’autre.")
                ->line('Vous n’avez plus rien à préparer pour ce trajet.');
        }

        $mail = (new MailMessage)
            ->subject("Nouvelle mission — {$r->code}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Vous coordonnez le trajet **{$r->code}**.")
            ->line("**Trajet :** {$r->from_location} → {$r->to_location}")
            ->line('**Départ :** ' . $this->departure())
            ->line("**Véhicules :** {$this->vehicleCount()}")
            ->line("**Client :** {$r->passenger_name} — {$r->passenger_phone}");

        if ($r->return_date) {
            $mail->line('**Retour :** ' . $r->return_date->format('d/m/Y à H:i'));
        }

        return $mail->line('Retrouvez le détail dans Mova Control.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $r = $this->reservation;

        return [
            'reservation_id' => $r->id,
            'code'           => $r->code,
            'title'          => $this->released ? 'Mission retirée' : 'Nouvelle mission',
            'message'        => $this->released
                ? "{$r->code} a été confiée à quelqu’un d’autre."
                : "{$r->from_location} → {$r->to_location}, " . $this->departure(),
            'from'           => $r->from_location,
            'to'             => $r->to_location,
            'trip_date'      => $r->trip_date?->toIso8601String(),
            'vehicles'       => $this->vehicleCount(),
            'type'           => $this->released ? 'mission_released' : 'mission_assigned',
        ];
    }

    /** @return array<string, mixed> */
    public function toFcm(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return [
            'title' => $data['title'],
            'body'  => $data['message'],
            'data'  => [
                'reservation_id' => (string) $this->reservation->id,
                // Deep link target inside control/. Released missions go to the
                // list, not to a detail the coordinator can no longer open.
                'screen'         => $this->released ? 'Missions' : 'MissionDetail',
            ],
            'android' => [
                'notification' => ['channel_id' => 'missions_channel'],
                // A mission assignment is time-critical in a way a marketing
                // push is not — it may arrive while the phone is dozing.
                'priority' => 'high',
            ],
            'apns' => [
                'payload' => ['aps' => ['sound' => 'default', 'content-available' => 1]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function toExpo(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return [
            'title' => $data['title'],
            'body'  => $data['message'],
            'data'  => [
                'reservation_id' => (string) $this->reservation->id,
                'screen'         => $this->released ? 'Missions' : 'MissionDetail',
            ],
        ];
    }

    private function departure(): string
    {
        return $this->reservation->trip_date?->format('d/m/Y à H:i') ?? 'date à confirmer';
    }

    /**
     * `loadCount` rather than `->buses->count()`.
     *
     * The relation is usually not loaded when this fires — the notification is
     * queued and rehydrates the model from the database — so counting through
     * the collection would load every bus row to produce one integer.
     */
    private function vehicleCount(): int
    {
        return (int) $this->reservation->buses()->count();
    }
}
