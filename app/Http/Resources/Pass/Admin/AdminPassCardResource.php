<?php

namespace App\Http\Resources\Pass\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A card, as staff need to see it.
 *
 * Differs from the client-facing `PassCardResource` in exactly one way: the
 * printed serial is shown in full. A support agent taking a call needs to match
 * the number the customer is reading off the card in their hand, and masking it
 * makes that impossible.
 *
 * The signature is STILL never exposed. It is the offline-verification artefact
 * and belongs on the chip and in the database, nowhere else — a staff UI has no
 * use for it, and a back-office is a browser like any other.
 */
class AdminPassCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'chip_uid' => $this->chip_uid,
            'printed_serial' => $this->printed_serial,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $this->client->phone,
            ]),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'blocked_reason' => $this->blocked_reason,
            'replaced_by_id' => $this->replaced_by_id,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'entitlement_expires_at' => $this->entitlement_expires_at?->toIso8601String(),
            'key_id' => $this->key_id,
            'issued_by' => $this->issued_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
