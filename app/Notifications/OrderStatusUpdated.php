<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotificationResource;

class OrderStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    protected $order;
    protected $customMessage;

    public function __construct(Order $order, ?string $customMessage = null)
    {
        $this->order = $order;
        $this->customMessage = $customMessage;
    }

    public function via($notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    public function toArray($notifiable): array
    {
        // Define logic for title/color based on status
        $status = $this->order->status;
        $title = 'Mise à jour de commande';
        $type = 'info'; // info, success, warning, danger

        if ($status === 'pending') {
            $title = 'Demande Reçue';
            $type = 'info'; // or a new type 'pending'
        } elseif ($status === 'converted' || $status === 'confirmed') {
            $title = 'Devis Prêt !';
            $type = 'quote_ready';
        } elseif ($status === 'cancelled') {
            $title = 'Commande Annulée';
            $type = 'cancelled';
        } elseif ($status === 'contacted') {
            $title = 'Dossier en cours';
            $type = 'driver_assigned';
        }

        return [
            'order_id' => $this->order->id,
            'title' => $title,
            'message' => $this->customMessage ?? "Le statut de votre commande pour {$this->order->destination} a changé : " . ucfirst($status),
            'trip_name' => $this->order->destination,
            'type' => $type,
            'status_color' => $this->getColor($type),
            'icon' => $this->getIcon($type),
        ];
    }

    public function toFcm($notifiable)
    {
        // Reuse data logic from toArray
        $data = $this->toArray($notifiable);

        return FcmMessage::create()
            ->setData([
                'order_id' => (string) $this->order->id,
                'type' => 'order_update'
            ])
            ->setNotification(
                FcmNotificationResource::create()
                    ->setTitle($data['title'])
                    ->setBody($data['message'])
            );
    }

    private function getColor($type) {
        return match($type) {
            'quote_ready' => '#10B981', // Green
            'cancelled' => '#EF4444', // Red
            'driver_assigned' => '#3B82F6', // Blue
            default => '#64748B', // Grey
        };
    }

    private function getIcon($type) {
        return match($type) {
            'quote_ready' => 'file-text',
            'cancelled' => 'x-circle',
            'driver_assigned' => 'user-check',
            default => 'bell',
        };
    }
}
