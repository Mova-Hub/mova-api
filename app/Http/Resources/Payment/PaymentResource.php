<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // The admin routes are keyed on the integer id, so it has to be
            // here or the back-office cannot address a payment at all. Harmless
            // to expose: every client-facing query is already scoped to the
            // caller, so knowing an id grants nothing.
            'id' => $this->id,
            'uuid' => $this->uuid,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_final' => $this->status->isFinal(),
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            // Masked: a full number in an API response ends up in logs and
            // caches, and the payer already knows which number they used.
            'payer_phone' => $this->maskPhone(),
            'reference' => $this->provider_reference,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            // `meta` is never exposed — it holds raw provider payloads.
        ];
    }

    private function maskPhone(): ?string
    {
        $phone = $this->payer_phone;

        if (! is_string($phone) || strlen($phone) < 4) {
            return $phone;
        }

        return str_repeat('•', max(0, strlen($phone) - 4)) . substr($phone, -4);
    }
}
