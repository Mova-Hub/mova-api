<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\NotifiesClient;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Your trip is tomorrow / your trip is today.
 *
 * Sent to the client, on every channel they have. Distinct from the dunning in
 * `SendPaymentReminders`, which is about money and goes out over SMS: this one
 * is about the journey, and it is the message that stops somebody missing a
 * coach they already paid for.
 *
 * The eve and the morning are genuinely different messages, not one message
 * with a substituted noun. "Demain" is a prompt to prepare; "aujourd'hui" is a
 * prompt to leave, so it leads with the departure time.
 */
class TripReminder extends Notification
{
    use NotifiesClient, Queueable;

    /** The eve of the trip. */
    public const EVE = 'eve';

    /** The morning of the trip. */
    public const DAY = 'day';

    public function __construct(
        public Order $order,
        public string $when = self::DAY,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $eve = $this->when === self::EVE;

        $mail = (new MailMessage)
            ->subject($eve
                ? 'Votre trajet Mova a lieu demain'
                : 'Votre trajet Mova a lieu aujourd\'hui')
            ->greeting('Bonjour '.($this->order->contact_name ?: '').',')
            ->line($eve
                ? 'Petit rappel : votre trajet est prévu demain.'
                : 'Votre trajet est prévu aujourd\'hui.');

        foreach ($this->details() as $label => $value) {
            $mail->line($label.' : '.$value);
        }

        return $mail
            ->action('Voir mon trajet', $this->deepLink())
            ->line('Notre equipe vous contacte si quoi que ce soit change.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trip_reminder',
            'when' => $this->when,
            'order_id' => $this->order->id,
            'code' => $this->order->reservation?->code,
            'title' => $this->title(),
            'body' => $this->body(),
            'route' => '/trip/'.$this->order->id,
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
     * The push payload, built once.
     *
     * `ExpoChannel` and `FcmChannel` each look for their own method name and
     * skip the notification entirely when it is missing, with no error. That
     * silence is why this is worth a shared builder rather than two hand
     * written arrays that can drift apart unnoticed.
     *
     * `data.route` is the app's own path, so tapping the notification opens the
     * trip rather than the home screen.
     *
     * @return array<string, mixed>
     */
    private function push(): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'data' => [
                'type' => 'trip_reminder',
                'order_id' => (string) $this->order->id,
                'route' => '/trip/'.$this->order->id,
            ],
        ];
    }

    private function title(): string
    {
        return $this->when === self::EVE ? 'Votre trajet est demain' : 'Votre trajet est aujourd\'hui';
    }

    /**
     * The one line a lock screen actually shows.
     *
     * Departure time first on the day itself, because that is the fact somebody
     * glancing at a notification at 05:40 needs. The destination leads on the
     * eve, when the useful information is which trip this is about.
     */
    private function body(): string
    {
        $time = $this->order->pickup_time;
        $to = $this->order->destination;

        if ($this->when === self::EVE) {
            return $time
                ? sprintf('Depart vers %s demain a %s.', $to, $time)
                : sprintf('Depart vers %s demain.', $to);
        }

        return $time
            ? sprintf('Depart a %s vers %s. Bon voyage.', $time, $to)
            : sprintf('Depart vers %s aujourd\'hui. Bon voyage.', $to);
    }

    /**
     * @return array<string, string>
     */
    private function details(): array
    {
        $reservation = $this->order->reservation;

        $details = [
            'Depart' => (string) $this->order->origin,
            'Destination' => (string) $this->order->destination,
        ];

        if ($this->order->pickup_time) {
            $details['Heure'] = (string) $this->order->pickup_time;
        }

        if ($reservation?->code) {
            $details['Reference'] = (string) $reservation->code;
        }

        // Plates are what a client looks for in a car park, so they are worth
        // the extra lines. Drivers' names are deliberately not here: the client
        // meets the coordinator, and a mail is not the place to publish a
        // driver's identity.
        $plates = $reservation?->buses->pluck('plate')->filter()->implode(', ');

        if ($plates) {
            $details['Vehicules'] = $plates;
        }

        return $details;
    }

    private function deepLink(): string
    {
        return rtrim((string) config('app.url'), '/').'/trip/'.$this->order->id;
    }
}
