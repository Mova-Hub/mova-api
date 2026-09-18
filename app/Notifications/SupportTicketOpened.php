<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Notifications\Concerns\NotifiesClient;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A client wrote in, and somebody has to answer.
 *
 * Addressed to STAFF, like `ManualPaymentRequested`, and for the same reason: a
 * support ticket cannot resolve itself. Until this existed, a ticket sat in the
 * table until an agent happened to open the right page.
 *
 * Mail matters most here. Unlike a chat message, a ticket is still worth reading
 * twenty minutes later, and it is work assigned to a person rather than a
 * notification about something that already happened.
 *
 * **The message body is not in here.** The subject is, because an agent needs to
 * know what they are being called to; the body is a customer's free text that
 * routinely carries a phone number, an amount or a photo description, and it does
 * not belong in an email sent to every active agent and in a push payload that
 * passes through Expo and Google. It is one tap away in the back office.
 */
class SupportTicketOpened extends Notification
{
    use NotifiesClient, Queueable;

    public function __construct(public SupportTicket $ticket) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nouveau message support : '.$this->subject())
            ->line($this->clientName().' a ouvert un ticket.')
            ->line('Sujet : '.$this->subject())
            ->line('Catégorie : '.$this->categoryLabel())
            ->action('Ouvrir le ticket', $this->backOfficeLink())
            ->line('Répondez depuis le back-office, le client est notifié automatiquement.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_opened',
            'ticket_id' => $this->ticket->id,
            'category' => $this->ticket->category,
            'client' => $this->clientName(),
            'title' => $this->title(),
            // `message` AND `body`: the in-app inbox reads the first while every
            // older notification emits the second. See RateYourTrip.
            'message' => $this->body(),
            'body' => $this->body(),
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
            'body' => $this->body(),
            'data' => [
                'type' => 'support_ticket_opened',
                'ticket_id' => (string) $this->ticket->id,
                'route' => '/support/'.$this->ticket->id,
            ],
        ];
    }

    private function title(): string
    {
        return 'Nouveau message support';
    }

    private function body(): string
    {
        return $this->clientName().' : '.$this->subject();
    }

    /** Truncated, so a 150 character subject does not become the whole banner. */
    private function subject(): string
    {
        return Str::limit($this->ticket->subject, 80);
    }

    private function clientName(): string
    {
        return $this->ticket->client?->name ?? 'Un client';
    }

    private function categoryLabel(): string
    {
        return match ($this->ticket->category) {
            'booking' => 'Réservation',
            'payment' => 'Paiement',
            'pass' => 'Mova Pass',
            'account' => 'Compte',
            default => 'Autre',
        };
    }

    private function backOfficeLink(): string
    {
        $base = config('app.frontend_url') ?: config('app.url');

        return rtrim((string) $base, '/').'/support/'.$this->ticket->id;
    }
}
