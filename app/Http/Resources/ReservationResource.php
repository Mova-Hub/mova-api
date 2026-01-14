<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => (string) $this->id,
            'code'            => $this->code,
            'trip_date'       => $this->trip_date,
            'from_location'   => $this->from_location,
            'to_location'     => $this->to_location,

            'passenger'       => [
                'name'  => $this->passenger_name,
                'phone' => $this->passenger_phone,
                'email' => $this->passenger_email,
            ],

            'seats'           => (int) $this->seats,
            'price_total'     => $this->price_total !== null ? (float) $this->price_total : null,
            'status'          => $this->status,
            'payment_status'  => $this->payment_status,

            'waypoints'       => $this->waypoints, // array or null
            'distance_km'     => $this->distance_km !== null ? (float) $this->distance_km : null,

            'buses'           => $this->whenLoaded('buses', function () {
                return $this->buses->map(fn($b) => [
                    'id'    => (string) $b->id,
                    'plate' => $b->plate,
                    'name'  => $b->name,
                    'status'=> $b->status,
                    'type'  => $b->type,
                ]);
            }),

            'event'     => $this->event,

            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'deleted_at'      => $this->deleted_at?->toIso8601String(),
        ];
    }
}
