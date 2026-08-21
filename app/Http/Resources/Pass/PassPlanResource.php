<?php

namespace App\Http\Resources\Pass;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PassPlan */
class PassPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            // Whole francs. XAF has no subunit, so there is nothing to divide.
            'price' => (int) $this->price,
            'currency' => $this->currency,
            'interval' => $this->interval->value,
            'interval_count' => (int) $this->interval_count,
            'duration_label' => $this->durationLabel(),
            // NULL means unlimited travel, which the app must render as a word,
            // not as "0 trajets".
            'trips' => $this->trips,
            'is_bundle' => $this->isBundle(),
            'metadata' => $this->metadata,
        ];
    }
}
