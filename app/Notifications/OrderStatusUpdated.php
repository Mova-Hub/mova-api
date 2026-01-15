<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

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
        $status = $this->order->status;
        $title = $this->getTitle($status);
        $type = $this->getType($status);

        return [
            'order_id' => $this->order->id,
            'title' => $title,
            'message' => $this->customMessage ?? "Statut : " . ucfirst($status),
            'trip_name' => $this->order->destination,
            'type' => $type,
            'status_color' => $this->getColor($type),
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
                // React Native Data Payload
                'order_id' => (string) $this->order->id,
                'status' => (string) $this->order->status,
                'type' => 'order_update',

                // Helper for React Native Navigation
                'screen' => 'MyProfile',
            ])
            ->custom([
                'android' => [
                    'notification' => [
                        'color' => $data['status_color'],
                        'icon' => 'ic_notification', // Ensure this icon exists in android/app/src/main/res/drawable
                        'channel_id' => 'orders_channel', // Important for Android 8+
                    ],
                    'priority' => 'high',
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'content-available' => 1, // Important for background updates
                        ],
                    ],
                ],
            ]);
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
            'quote_ready' => '#10B981', // Green
            'cancelled' => '#EF4444', // Red
            'driver_assigned' => '#3B82F6', // Blue
            default => '#64748B', // Grey
        };
    }
}
