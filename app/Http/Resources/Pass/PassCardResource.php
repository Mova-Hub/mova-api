<?php

namespace App\Http\Resources\Pass;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PassCard */
class PassCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // Masked, always. The full serial is an activation credential — it
            // is printed on the card the owner is holding, so showing it back
            // in an API response adds nothing and puts it in logs, caches and
            // screenshots.
            'serial_hint' => $this->maskSerial(),
            'chip_uid' => $this->chip_uid,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'blocked_reason' => $this->blocked_reason,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'entitlement_expires_at' => $this->entitlement_expires_at?->toIso8601String(),
        ];
    }

    private function maskSerial(): ?string
    {
        $serial = $this->printed_serial;

        if (! is_string($serial) || $serial === '') {
            return null;
        }

        return str_repeat('•', max(0, strlen($serial) - 4)) . substr($serial, -4);
    }
}
