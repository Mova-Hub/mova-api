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
            'reservation_status' => $res ? $res->status : $this->status,  // Use reservation status if converted
            'payment_status' => $res?->payment_status,
            'itinerary' => [
                'from' => $this->origin,
                'to' => $this->destination,
                // Prioritize the finalized Reservation data, fallback to the original Order data
                'waypoints' => $res ? $res->waypoints : $this->waypoints,
                'distance_km' => $res ? (float) $res->distance_km : (float) $this->distance_km,
                'date' => $this->pickup_date?->translatedFormat('d F Y'), // "15 Janvier 2026"
                // The formatted date above cannot be parsed, compared or
                // sorted, so the app had no way to tell an upcoming trip from a
                // past one. These carry the same instants in a machine form;
                // the display strings stay for the existing web client.
                'date_iso' => $this->pickup_date?->toDateString(),
                'time' => $this->pickup_time,
                'return_date' => $this->return_date?->translatedFormat('d F Y'),
                'return_date_iso' => $this->return_date?->toDateString(),
                'return_time' => $this->return_time,
            ],
            'passengers' => $this->passengers,
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
                // The confirmed reservation price wins; before conversion, fall
                // back to what the app quoted at submission, so a client is not
                // shown "0 FCFA" for an order they were given a figure for.
                'total' => $res
                    ? (float) $res->price_total
                    : (float) ($this->quoted_total ?? 0),
                'is_estimate' => ! $res && $this->quoted_total !== null,
                'is_paid' => $res?->payment_status === 'paid',
            ],
            'started_at' => $res?->started_at,
            'completed_at' => $res?->completed_at,
            'internal_notes' => $this->internal_notes,
        ];
    }
}
