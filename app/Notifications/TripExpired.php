<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\NotifiesClient;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Your request, or your booking, lapsed without running.
 *
 * Sent to the client when `orders:expire` marks something past its travel date.
 * Until now that sweep told nobody: a request simply stopped appearing under
 * "A venir" one morning, and the only way to find out was to open the app and
 * notice. Silence there reads as the app losing a booking.
 *
 * **Two audiences in one class, because the facts differ and the tone must
 * not.** An unanswered REQUEST lapsing is routine, and the message invites
 * rebooking. A CONFIRMED trip lapsing is an operational failure on Mova's side,
 * possibly with money attached, and the message says so plainly and promises
 * contact rather than inviting the client to try again as though nothing
 * happened. Writing one breezy message for both would be the wrong register for
 * somebody who paid.
 */
class TripExpired extends Notification
{
    use NotifiesClient, Queueable;

    public function __construct(
        public Order $order,
        /** True when a confirmed reservation lapsed, not merely an unanswered request. */
        public bool $wasConfirmed = false,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->wasConfirmed
                ? 'Votre trajet Mova n\'a pas pu avoir lieu'
                : 'Votre demande Mova a expiré')
            ->greeting('Bonjour '.($this->order->contact_name ?: '').',');

        if ($this->wasConfirmed) {
            $mail->line('Votre trajet confirmé n\'a pas été effectué à la date prévue.')
                ->line('Notre equipe reprend contact avec vous pour le reprogrammer ou régulariser votre paiement.');
        } else {
            $mail->line('Votre demande de trajet n\'a pas abouti avant la date souhaitée, elle est donc clôturée.')
                ->line('Vous pouvez en soumettre une nouvelle à tout moment.');
        }

        return $mail
            ->line('Trajet : '.$this->order->origin.' vers '.$this->order->destination)
            ->action('Ouvrir Mova', $this->deepLink());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trip_expired',
            'order_id' => $this->order->id,
            'code' => $this->order->reservation?->code,
            'was_confirmed' => $this->wasConfirmed,
            'title' => $this->title(),
            /*
             * `message` AND `body`.
             *
             * The in-app inbox reads `data['message']`, while every existing
             * notification here emits `body`, so those render as a blank line.
             * The controller now falls back, but both keys are sent so this one
             * is correct against either version of the reader.
             */
            'message' => $this->body(),
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
     * @return array<string, mixed>
     */
    private function push(): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'data' => [
                'type' => 'trip_expired',
                'order_id' => (string) $this->order->id,
                'route' => '/trip/'.$this->order->id,
            ],
        ];
    }

    private function title(): string
    {
        return $this->wasConfirmed ? 'Trajet non effectué' : 'Demande expirée';
    }

    private function body(): string
    {
        $where = $this->order->origin.' vers '.$this->order->destination;

        return $this->wasConfirmed
            ? 'Votre trajet '.$where.' n\'a pas eu lieu. Notre equipe vous recontacte.'
            : 'Votre demande '.$where.' est arrivée à échéance sans confirmation.';
    }

    private function deepLink(): string
    {
        return rtrim((string) config('app.url'), '/').'/trip/'.$this->order->id;
    }
}
