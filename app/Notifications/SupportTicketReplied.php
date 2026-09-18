<?php

namespace App\Notifications;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Notifications\Concerns\NotifiesClient;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Somebody answered your ticket.
 *
 * Sent to the CLIENT, from the back office. The preview carries our own reply,
 * which is safe to put in a push payload in a way the customer's original
 * message is not: it is text Mova wrote, to the person it was written for.
 *
 * Still truncated, because a push banner shows two lines and a 2000 character
 * reply in a notification payload is bytes nobody reads.
 */
class SupportTicketReplied extends Notification
{
    use NotifiesClient, Queueable;

    public function __construct(
        public SupportTicket $ticket,
        public SupportMessage $message,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Réponse à votre demande : '.Str::limit($this->ticket->subject, 60))
            ->greeting('Bonjour '.($this->ticket->client?->name ?: '').',')
            ->line('Notre équipe a répondu à votre demande.')
            ->line($this->preview())
            ->action('Voir la conversation', $this->deepLink())
            ->line('Vous pouvez répondre directement depuis l\'application.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_replied',
            'ticket_id' => $this->ticket->id,
            'message_id' => $this->message->id,
            'title' => $this->title(),
            // `message` AND `body`: the in-app inbox reads the first while every
            // older notification emits the second. See RateYourTrip.
            'message' => $this->preview(),
            'body' => $this->preview(),
            'route' => '/support/'.$this->ticket->id,
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
                'type' => 'support_ticket_replied',
                'ticket_id' => (string) $this->ticket->id,
                'route' => '/support/'.$this->ticket->id,
            ],
        ];
    }

    /**
     * Named, when we know the name.
     *
     * "Awa a répondu" beats "Mova a répondu": support is a person answering, and
     * the sender's name is already shown against the message in the thread.
     */
    private function title(): string
    {
        $name = $this->message->sender?->name;

        return $name ? $name.' a répondu' : 'Réponse de Mova';
    }

    private function preview(): string
    {
        return Str::limit((string) $this->message->body, 140);
    }

    private function deepLink(): string
    {
        return rtrim((string) config('app.url'), '/').'/support/'.$this->ticket->id;
    }
}
