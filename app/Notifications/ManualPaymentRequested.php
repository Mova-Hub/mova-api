<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A client asked to pay by cash or transfer, and somebody has to go and collect
 * it.
 *
 * The only notification here addressed to STAFF rather than to a customer, and
 * it exists because a manual payment is the one kind that cannot settle itself.
 * Mobile money resolves through a webhook or the reconciler whether anybody is
 * watching or not; cash sits at `processing` until a person acts, and until now
 * nothing told that person it was there. Ops had to be looking at the Paiements
 * page at the right moment.
 *
 * That is also how a separate bug stayed hidden for so long: the request was
 * expiring after fifteen minutes, and since nothing announced it, the only
 * evidence was its absence from a list nobody had reason to refresh.
 *
 * Mail and the database, plus push for the staff who have registered a device.
 * Mail is the one that matters: this is work assigned to a person, and unlike a
 * chat message it is still worth reading twenty minutes later.
 */
class ManualPaymentRequested extends Notification
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];

        if (method_exists($notifiable, 'routeNotificationForFcm')
            && ! empty($notifiable->routeNotificationForFcm())) {
            $channels[] = \App\Channels\FcmChannel::class;
        }

        if (method_exists($notifiable, 'routeNotificationForExpo')
            && ! empty($notifiable->routeNotificationForExpo())) {
            $channels[] = \App\Channels\ExpoChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->payment;

        return (new MailMessage)
            ->subject('Demande de paiement '.$this->providerLabel().' : '.$this->amount())
            ->line($this->clientName().' souhaite regler '.$this->amount().' en '.$this->providerLabel().'.')
            ->line('Reference : '.($payment->provider_reference ?: $payment->uuid))
            ->line($this->payableLine())
            ->action('Ouvrir les paiements', rtrim((string) config('app.frontend_url', config('app.url')), '/').'/payments')
            ->line('Confirmez le paiement une fois l’argent encaisse, ou marquez-le comme echoue.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'manual_payment_requested',
            'payment_id' => $this->payment->id,
            'payment_uuid' => $this->payment->uuid,
            'provider' => $this->payment->provider_code,
            'amount' => (int) $this->payment->amount,
            'currency' => $this->payment->currency,
            'client' => $this->clientName(),
            'title' => 'Demande de paiement '.$this->providerLabel(),
            'body' => $this->clientName().' : '.$this->amount(),
            'route' => '/payments',
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
            'title' => 'Demande de paiement '.$this->providerLabel(),
            'body' => $this->clientName().' souhaite regler '.$this->amount().'.',
            'data' => [
                'type' => 'manual_payment_requested',
                'payment_uuid' => (string) $this->payment->uuid,
                'route' => '/payments',
            ],
        ];
    }

    private function amount(): string
    {
        return number_format((float) $this->payment->amount, 0, ',', ' ').' '.$this->payment->currency;
    }

    private function providerLabel(): string
    {
        return $this->payment->provider?->label ?? $this->payment->provider_code;
    }

    /**
     * Who is paying.
     *
     * Falls back to the contact name on the booking, because a counter
     * collection for a walk-in has no account behind it and "un client" tells
     * an agent nothing.
     */
    private function clientName(): string
    {
        return $this->payment->client?->name
            ?? $this->payment->payable?->contact_name
            ?? 'Un client';
    }

    private function payableLine(): string
    {
        $payable = $this->payment->payable;

        if ($payable && isset($payable->origin, $payable->destination)) {
            return 'Trajet : '.$payable->origin.' vers '.$payable->destination;
        }

        return 'Paiement '.$this->payment->payable_type;
    }
}
