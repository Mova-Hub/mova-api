<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use Throwable;

class OrderStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    // Retry settings for Production/cPanel
    public $tries = 3;
    public $backoff = [60, 300, 600];

    protected $order;
    protected $customMessage;

    public function __construct(Order $order, ?string $customMessage = null)
    {
        $this->order = $order;
        $this->customMessage = $customMessage;
    }

    public function via($notifiable): array
    {
        $channels = ['database'];

        // 1. Email check: Use the 'email' field from your Client model
        if (!empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        // 2. FCM check: Your routeNotificationForFcm() handles the token array
        if (count($notifiable->routeNotificationForFcm()) > 0) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $status = $this->order->status;
        $title = $this->getTitle($status);

        return (new MailMessage)
            ->subject("Update Commande #{$this->order->id} - {$title}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line($this->customMessage ?? "Le statut de votre commande pour **{$this->order->destination}** a été mis à jour.")
            ->line("Nouveau statut : **" . $this->getStatusLabel($status) . "**")
            ->action('Voir ma commande', url(config('app.url') . '/orders/' . $this->order->id))
            ->line("Merci de votre confiance.")
            ->salutation("L'équipe Mova");
    }

    public function toArray($notifiable): array
    {
        $status = $this->order->status;
        return [
            'order_id' => $this->order->id,
            'title' => $this->getTitle($status),
            'message' => $this->customMessage ?? "Statut : " . $this->getStatusLabel($status),
            'trip_name' => $this->order->destination,
            'type' => $this->getType($status),
            'status_color' => $this->getColor($this->getType($status)),
        ];
    }

    public function toFcm($notifiable): FcmMessage
    {
        $data = $this->toArray($notifiable);

        return (new FcmMessage(notification: new FcmNotification(
                title: $data['title'],
                body: $data['message'],
            )))
            ->data([
                'order_id' => (string) $this->order->id,
                'status' => (string) $this->order->status,
                'screen' => 'MyProfile', // Helper for React Native
            ])
            ->custom([
                'android' => [
                    'notification' => [
                        'color' => $data['status_color'],
                        'channel_id' => 'orders_channel',
                    ],
                    'priority' => 'high',
                ],
                'apns' => [
                    'payload' => ['aps' => ['sound' => 'default', 'content-available' => 1]],
                ],
            ]);
    }

    public function failed(Throwable $exception): void
    {
        // Log the error in storage/logs/laravel.log
        Log::error("Notification Error (Order #{$this->order->id}): " . $exception->getMessage());
    }

    /* ------------------------------- Helpers ------------------------------ */

    private function getStatusLabel($status) {
        return match($status) {
            'pending' => 'En attente',
            'confirmed', 'converted' => 'Confirmée',
            'cancelled' => 'Annulée',
            'contacted' => 'En cours',
            default => ucfirst($status),
        };
    }

    private function getTitle($status) {
        return match($status) {
            'pending' => 'Demande Reçue',
            'converted', 'confirmed' => 'Devis Prêt !',
            'cancelled' => 'Commande Annulée',
            'contacted' => 'Dossier en cours',
            default => 'Mise à jour de commande',
        };
    }

    private function getType($status) {
        return match($status) {
            'pending' => 'info',
            'converted', 'confirmed' => 'quote_ready',
            'cancelled' => 'cancelled',
            'contacted' => 'driver_assigned',
            default => 'info',
        };
    }

    private function getColor($type) {
        return match($type) {
            'quote_ready' => '#10B981',
            'cancelled' => '#EF4444',
            'driver_assigned' => '#3B82F6',
            default => '#64748B',
        };
    }
}
