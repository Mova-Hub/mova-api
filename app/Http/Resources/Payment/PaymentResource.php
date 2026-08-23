<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $provider = $this->provider;

        return [
            // The admin routes are keyed on the integer id, so it has to be
            // here or the back-office cannot address a payment at all. Harmless
            // to expose: every client-facing query is already scoped to the
            // caller, so knowing an id grants nothing.
            'id' => $this->id,
            'uuid' => $this->uuid,

            'provider' => $this->provider_code,
            // Falls back to the code so a payment made through a provider that
            // was later deleted still renders as something, rather than a blank
            // row in a client's history.
            'provider_label' => $provider?->label ?? $this->provider_code,
            'provider_logo' => $provider?->logoUrl(),
            'provider_color' => $provider?->brand_color,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_final' => $this->status->isFinal(),

            'kind' => $this->kind,
            'channel' => $this->channel,

            'amount' => (int) $this->amount,
            'fee_amount' => (int) $this->fee_amount,
            'currency' => $this->currency,

            // Masked: a full number in an API response ends up in logs and
            // caches, and the payer already knows which number they used.
            'payer_phone' => $this->maskedPayerPhone(),
            'reference' => $this->provider_reference,
            'failure_reason' => $this->failure_reason,

            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),

            // `meta` and `idempotency_key` are never exposed — one holds raw
            // provider payloads, the other is sent to providers as a request id.
        ];
    }
}
