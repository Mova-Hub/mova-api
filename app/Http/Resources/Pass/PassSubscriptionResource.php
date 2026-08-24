<?php

namespace App\Http\Resources\Pass;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PassSubscription */
class PassSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'plan' => new PassPlanResource($this->whenLoaded('plan')),

            /*
             * Who this belongs to — `whenLoaded`, so the client-facing
             * `/app/v1/pass/*` responses (which never load it, and where the
             * subscriber already knows who they are) are unchanged.
             *
             * The staff controllers DO eager-load it and could not display it:
             * a subscriptions table with no owner column is a list of
             * anonymous rows.
             */
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $this->client->phone,
            ]),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            // Derived server-side so the app never has to reimplement the rule
            // — and cannot disagree with the inspector about it.
            'is_valid' => $this->isCurrentlyValid(),
            'is_expiring_soon' => $this->isExpiringSoon(),
            'days_remaining' => $this->daysRemaining(),
            'trips_remaining' => $this->trips_remaining,
            'price_paid' => (int) $this->price_paid,
            'currency' => $this->currency,
            'auto_renew' => (bool) $this->auto_renew,
            'created_at' => $this->created_at?->toIso8601String(),
            // `signature` and `key_id` are deliberately absent: they are the
            // offline-verification artefact, of no use to the app and one more
            // thing that would be sitting in a phone's HTTP cache.
        ];
    }
}
