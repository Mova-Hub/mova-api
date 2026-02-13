<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $res = $this->reservation;

        return [
            'id' => $this->id,
            'code' => $res?->code,
            'event_type' => $this->event_type,
            'status' => $this->status,
            'reservation_status' => $res ? $res->status : $this->status,  // Use reservation status if converted for better tracking
            'itinerary' => [
                'from' => $this->origin,
                'to' => $this->destination,
                'date' => $this->pickup_date?->translatedFormat('d F Y'), // "15 Janvier 2026"
                'time' => $this->pickup_time,
            ],
            'vehicles' => $res ? $res->buses->map(fn($bus) => [
                'id' => $bus->id,
                'plate' => $bus->plate,
                'type' => $bus->type,
                'label' => "{$bus->plate} ({$bus->type})",
                // Gestion Pro du Driver (User Model)
                'driver' => $bus->driver ? [
                    'name' => $bus->driver->name,
                    // 'phone' => $bus->driver->phone,
                    'avatar' => $bus->driver->avatar_url ?? null,
                    'license' => $bus->driver->license_no,
                ] : null,
            ]) : [],
            'pricing' => [
                'total' => $res ? (float) $res->price_total : 0,
                'is_paid' => $res?->payment_status === 'paid',
            ],
            'started_at' => $res?->started_at,
            'completed_at' => $res?->completed_at,
            'internal_notes' => $this->internal_notes,
        ];
    }
}
