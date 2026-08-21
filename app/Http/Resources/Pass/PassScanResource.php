<?php

namespace App\Http\Resources\Pass;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PassScan */
class PassScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'verdict' => $this->verdict->value,
            'verdict_label' => $this->verdict->label(),
            'tone' => $this->verdict->tone(),
            'reason' => $this->reason,
            'source' => $this->source->value,
            'bus_line' => $this->bus_line,
            // The device clock is what the rider saw; it is also the field PRD
            // §4.4 treats as untrusted, so both timestamps are exposed and the
            // back-office compares them.
            'scanned_at' => $this->scanned_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
        ];
    }
}
