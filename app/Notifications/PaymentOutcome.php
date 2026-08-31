<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\NotifiesClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your payment went through", or "it did not".
 *
 * The gap this closes: money could arrive and nobody told the client. A counter
 * collection notified them, because `ReservationController::payment()` sends its
 * own message, but a payment that settled through a webhook, through
 * reconciliation, or through an admin confirming it by hand settled in silence.
 * The client watched a sheet spin, closed it, and had no receipt of any kind.
 *
 * **Generic over payables, deliberately.** One class serves a charter order, a
 * Pass subscription and a back office reservation, because all three implement
 * `Payable` and the only thing that differs is the description they give
 * themselves. A notification per payable would be three copies of the same
 * French drifting apart.
 *
 * Sent from `PaymentService::apply()`, which is the one place a payment reaches
 * a terminal state, and AFTER its transaction commits. Inside it, a rollback
 * would have told somebody their trip was paid for when it was not.
 */
class PaymentOutcome extends Notification implements ShouldQueue
{
    use NotifiesClient, Queueable;

    public $tries = 3;
    public $backoff = [60, 300, 600];

    public function __construct(
        protected Payment $payment,
        protected bool $succeeded,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $amount = $this->amount();
        $what = $this->describe();

        if (! $this->succeeded) {
            return (new MailMessage)
                ->subject('Paiement non abouti')
                ->greeting("Bonjour {$notifiable->name},")
                ->line("Votre paiement de {$amount} pour {$what} n'a pas abouti.")
                ->line($this->payment->failure_reason ?: 'Vous pouvez réessayer depuis l’application.')
                // No money moved, so this must not read like a demand. It is a
                // retry invitation, not an invoice.
                ->line('Aucun montant n’a été débité.');
        }

        $mail = (new MailMessage)
            ->subject("Paiement reçu — {$amount}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Nous avons bien reçu votre paiement de **{$amount}** pour {$what}.")
            ->line('Moyen de paiement : ' . ($this->payment->provider?->label ?? $this->payment->provider_code));

        if ($this->payment->provider_reference) {
            // The one thing worth keeping from a receipt: what to quote when
            // something is disputed.
            $mail->line("Référence : {$this->payment->provider_reference}");
        }

        return $mail->line('Merci d’avoir choisi Mova.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'payment_uuid' => $this->payment->uuid,
            'title' => $this->succeeded ? 'Paiement reçu' : 'Paiement non abouti',
            'message' => $this->succeeded
                ? "{$this->amount()} pour {$this->describe()}."
                : ($this->payment->failure_reason ?: 'Le paiement n’a pas abouti. Vous pouvez réessayer.'),
            'amount' => (int) $this->payment->amount,
            'currency' => $this->payment->currency,
            'reference' => $this->payment->provider_reference,
            'type' => $this->succeeded ? 'payment_succeeded' : 'payment_failed',
            'status_color' => $this->succeeded ? '#047857' : '#DC2626',
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
                'payment_uuid' => (string) $this->payment->uuid,
                // Deep links to the thing that was paid for, not to a payments
                // list. Somebody tapping this wants their trip, not a ledger.
                'screen' => $this->screen(),
                'payable_id' => (string) $this->payment->payable_id,
            ],
            'android' => [
                'notification' => [
                    'color' => $data['status_color'],
                    'channel_id' => 'payments_channel',
                ],
                'priority' => 'high',
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
            'data' => [
                'payment_uuid' => (string) $this->payment->uuid,
                'screen' => $this->screen(),
                'payable_id' => (string) $this->payment->payable_id,
            ],
        ];
    }

    private function amount(): string
    {
        return number_format((float) $this->payment->amount, 0, ',', ' ') . ' FCFA';
    }

    /**
     * What was paid for, in the payable's own words.
     *
     * Falls back rather than throwing: a payment whose payable has been deleted
     * still deserves a receipt, and "votre commande" is truer than a crash.
     */
    private function describe(): string
    {
        try {
            return $this->payment->payable?->paymentDescription() ?: 'votre commande';
        } catch (\Throwable) {
            return 'votre commande';
        }
    }

    /** Where the app should open. Keyed on the payable, not on the provider. */
    private function screen(): string
    {
        return match ($this->payment->payable_type) {
            \App\Models\PassSubscription::class => 'Pass',
            default => 'TripDetail',
        };
    }
}
