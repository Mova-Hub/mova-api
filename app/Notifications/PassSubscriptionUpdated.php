<?php

namespace App\Notifications;

use App\Models\PassSubscription;
use App\Notifications\Concerns\NotifiesClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Anything that happens to a Mova Pass.
 *
 * The gap this closes is the whole of Pass. Nothing in the Pass domain notified
 * anybody about anything: a subscription could activate, lapse or be cancelled
 * and the client would find out by opening the app and looking, or by being
 * refused at a bus door. A Pass is a thing people rely on to get to work, and it
 * expiring silently is the worst version of that.
 *
 * One class with an event, mirroring `ReservationStatusUpdated`, rather than
 * four classes. The French lives in one `match` where it can be read as a set
 * and kept consistent, instead of drifting across four files.
 */
class PassSubscriptionUpdated extends Notification implements ShouldQueue
{
    use NotifiesClient, Queueable;

    public $tries = 3;
    public $backoff = [60, 300, 600];

    public const ACTIVATED = 'activated';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const EXPIRING = 'expiring';

    public function __construct(
        protected PassSubscription $subscription,
        /** One of the constants above. */
        protected string $event,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->planName();
        $mail = (new MailMessage)->greeting("Bonjour {$notifiable->name},");

        return match ($this->event) {
            self::ACTIVATED => $mail
                ->subject("Votre Mova Pass {$plan} est actif")
                ->line("Votre abonnement **{$plan}** est actif.")
                ->line($this->validityLine())
                // The one instruction that matters at a bus door.
                ->line('Présentez votre carte au contrôleur à chaque montée.'),

            self::EXPIRING => $mail
                ->subject('Votre Mova Pass arrive à échéance')
                ->line("Votre abonnement **{$plan}** expire {$this->whenExpires()}.")
                ->line('Renouvelez-le depuis l’application pour continuer à voyager sans interruption.'),

            self::EXPIRED => $mail
                ->subject('Votre Mova Pass a expiré')
                ->line("Votre abonnement **{$plan}** a expiré.")
                ->line('Votre carte ne sera plus acceptée à bord tant qu’un nouvel abonnement n’est pas actif.')
                ->line('Vous pouvez le renouveler à tout moment depuis l’application.'),

            self::CANCELLED => $mail
                ->subject('Votre Mova Pass a été annulé')
                ->line("Votre abonnement **{$plan}** a été annulé.")
                ->line($this->validityLine())
                ->line('Vous pouvez souscrire à nouveau quand vous le souhaitez.'),

            default => $mail
                ->subject('Votre Mova Pass a été mis à jour')
                ->line("Votre abonnement **{$plan}** a été mis à jour."),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'plan' => $this->planName(),
            'title' => $this->title(),
            'message' => $this->body(),
            'type' => 'pass_' . $this->event,
            'status_color' => match ($this->event) {
                self::ACTIVATED => '#047857',
                self::EXPIRING => '#B45309',
                self::EXPIRED, self::CANCELLED => '#DC2626',
                default => '#2563EB',
            },
        ];
    }

    /** @return array<string, mixed> */
    public function toFcm(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return [
            'title' => $data['title'],
            'body' => $data['message'],
            'data' => [
                'subscription_id' => (string) $this->subscription->id,
                'screen' => 'Pass',
            ],
            'android' => [
                'notification' => [
                    'color' => $data['status_color'],
                    'channel_id' => 'pass_channel',
                ],
                // An expiring Pass is time critical in a way a receipt is not:
                // it is the difference between boarding and not boarding.
                'priority' => $this->event === self::EXPIRING ? 'high' : 'normal',
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
            'body' => $data['message'],
            'data' => ['subscription_id' => (string) $this->subscription->id, 'screen' => 'Pass'],
        ];
    }

    private function title(): string
    {
        return match ($this->event) {
            self::ACTIVATED => 'Mova Pass actif',
            self::EXPIRING => 'Votre Pass arrive à échéance',
            self::EXPIRED => 'Votre Pass a expiré',
            self::CANCELLED => 'Abonnement annulé',
            default => 'Mova Pass mis à jour',
        };
    }

    private function body(): string
    {
        $plan = $this->planName();

        return match ($this->event) {
            self::ACTIVATED => "{$plan} — " . $this->validityLine(),
            self::EXPIRING => "{$plan} expire {$this->whenExpires()}. Renouvelez pour continuer.",
            self::EXPIRED => "{$plan} a expiré. Votre carte n'est plus acceptée à bord.",
            self::CANCELLED => "{$plan} a été annulé.",
            default => "{$plan} a été mis à jour.",
        };
    }

    private function planName(): string
    {
        return $this->subscription->plan?->name ?? 'Mova Pass';
    }

    /**
     * How long it runs for.
     *
     * A cancelled Pass usually stays valid until the end of the period already
     * paid for, so saying "annulé" alone would read as "it stopped today" and
     * cost somebody a journey they had already paid for.
     */
    private function validityLine(): string
    {
        $end = $this->subscription->expires_at;

        return $end
            ? 'valable jusqu’au ' . $end->format('d/m/Y')
            : 'consultez l’application pour la période de validité';
    }

    private function whenExpires(): string
    {
        $end = $this->subscription->expires_at;

        return $end ? 'le ' . $end->format('d/m/Y') : 'bientôt';
    }
}
