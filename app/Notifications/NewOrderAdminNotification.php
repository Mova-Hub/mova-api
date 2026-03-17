<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class NewOrderAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [60, 300, 600];

    protected $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function via($notifiable): array
    {
        // We will send an email AND save it to the database for the admin panel
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $fleet = json_encode($this->order->fleet_requirements); // Convert array to string for the email
        $clientName = $this->order->client->name ?? $this->order->contact_name;

        return (new MailMessage)
            ->subject("Nouvelle Demande de Trajet : {$this->order->origin} -> {$this->order->destination}")
            ->greeting("Bonjour l'équipe Mova,")
            ->line("Une nouvelle demande de réservation a été soumise par **{$clientName}**.")
            ->line("**Détails du trajet :**")
            ->line("- De : {$this->order->origin}")
            ->line("- À : {$this->order->destination}")
            ->line("- Date : {$this->order->pickup_date->format('d/m/Y')} à {$this->order->pickup_time}")
            ->line("- Flotte demandée : {$fleet}")
            ->line("- Téléphone Contact : {$this->order->contact_phone}")
            ->action('Voir la demande', url(config('app.url') . '/admin/orders/' . $this->order->id))
            ->salutation("Système Mova");
    }

    public function toArray($notifiable): array
    {
        // and displayed in the Admin Dashboard bell icon.
        return [
            'order_id' => $this->order->id,
            'title' => 'Nouvelle Demande de Trajet',
            'message' => "{$this->order->contact_name} a demandé un trajet de {$this->order->origin} vers {$this->order->destination}.",
            'type' => 'new_order',
            'status_color' => '#3B82F6', // Blue for new leads
        ];
    }
}
