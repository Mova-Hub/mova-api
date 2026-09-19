<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,

            'category' => $this->category?->value,
            'category_label' => $this->category?->label(),
            /** direct | overhead, so the client groups a P&L without a lookup table. */
            'nature' => $this->category?->nature(),

            'direction' => $this->direction,
            'is_reversal' => $this->isReversal(),
            /*
             * Unsigned, as stored. A client that wants to add these up uses
             * `signed_amount`; showing a bare negative in a list of costs reads
             * as an error to everyone except the person who wrote the query.
             */
            'amount' => (int) $this->amount,
            'signed_amount' => $this->signedAmount(),
            'currency' => $this->currency,

            'method' => $this->method?->value,
            'method_label' => $this->method?->label(),

            /** The cash-basis date. Every report groups by this one. */
            'paid_at' => $this->paid_at?->toDateString(),

            'bus_id' => $this->bus_id,
            // Loaded by the service's eager load; absent rather than null-ish
            // when it was not, so a caller can tell "no bus" from "not asked".
            'bus_plate' => $this->whenLoaded('bus', fn () => $this->bus?->plate),
            'reservation_id' => $this->reservation_id,

            'supplier_name' => $this->supplier_name,
            'reference' => $this->reference,
            'note' => $this->note,

            'receipt_url' => $this->receiptUrl(),
            'receipt_mime' => $this->receipt_mime,

            'reverses_id' => $this->reverses_id,

            'recorded_by' => $this->recorded_by,
            'recorded_by_name' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
