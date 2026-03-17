<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use App\Channels\FcmChannel;
use Throwable;

class ReservationStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [60, 300, 600];

    protected $reservation;
    protected $customMessage;

    public function __construct(Reservation $reservation, ?string $customMessage = null)
    {
        $this->reservation = $reservation;
        $this->customMessage = $customMessage;
    }

    public function via($notifiable): array
    {
        $channels = ['database'];

        if (!empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'routeNotificationForFcm')) {
            $tokens = $notifiable->routeNotificationForFcm();
            if (!empty($tokens)) {
                $channels[] = FcmChannel::class;
            }
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $status = $this->reservation->status;
        $title = $this->getTitle($status);

        return (new MailMessage)
            ->subject("Update Réservation #{$this->reservation->code} - {$title}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line($this->customMessage ?? "Le statut de votre réservation pour **{$this->reservation->to_location}** a été mis à jour.")
            ->line("Nouveau statut : **" . $this->getStatusLabel($status) . "**")
            ->action('Voir ma réservation', url(config('app.url') . '/reservations/' . $this->reservation->id))
            ->line("Merci d'avoir choisi Mova.");
    }

    public function toArray($notifiable): array
    {
        $status = $this->reservation->status;
        return [
            'reservation_id' => $this->reservation->id,
            'title' => $this->getTitle($status),
            'message' => $this->customMessage ?? "Statut : " . $this->getStatusLabel($status),
            'trip_name' => $this->reservation->to_location,
            'type' => $this->getType($status),
            'status_color' => $this->getColor($this->getType($status)),
        ];
    }

    public function toFcm($notifiable): array
    {
        $data = $this->toArray($notifiable);

        return [
            'title' => $data['title'],
            'body' => $data['message'],
            'data' => [
                'reservation_id' => (string) $this->reservation->id,
                'status' => (string) $this->reservation->status,
                'screen' => 'ReservationDetails', // Point this to your React Native screen!
            ],
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
        ];
    }

    public function failed(Throwable $exception): void
    {
        Log::error("Notification Error (Reservation #{$this->reservation->id}): " . $exception->getMessage());
    }

    /* ------------------------------- Helpers ------------------------------ */

    private function getStatusLabel($status) {
        return match($status) {
            'pending' => 'En attente',
            'confirmed' => 'Confirmée',
            'in_progress' => 'Trajet en cours',
            'completed' => 'Terminée',
            'cancelled' => 'Annulée',
            default => ucfirst($status),
        };
    }

    private function getTitle($status) {
        return match($status) {
            'confirmed' => 'Réservation Confirmée',
            'in_progress' => 'Votre chauffeur est en route !',
            'completed' => 'Trajet Terminé',
            'cancelled' => 'Réservation Annulée',
            default => 'Mise à jour de réservation',
        };
    }

    private function getType($status) {
        return match($status) {
            'confirmed' => 'confirmed',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'info',
        };
    }

    private function getColor($type) {
        return match($type) {
            'confirmed' => '#10B981', // Green
            'in_progress' => '#F59E0B', // Orange/Yellow
            'completed' => '#3B82F6', // Blue
            'cancelled' => '#EF4444', // Red
            default => '#64748B',
        };
    }
}
