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

            /*
             * Both are ACCEPTED by PassPlanController's validator and were
             * never returned, so the back-office could set them and then had no
             * way to read them back — its "Inactive" badge rendered a branch
             * that could not be reached, and the plans list could not sort by
             * the very column the API orders on.
             *
             * The client-facing `/app/v1/pass/plans` only ever returns active
             * plans, so `is_active` is always true there; it is the staff
             * catalogue that needs it.
             */
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,

            'metadata' => $this->metadata,
        ];
    }
}
