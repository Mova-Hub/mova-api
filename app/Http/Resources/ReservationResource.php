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

            /*
             * The two links a detail screen needs and could not follow.
             *
             * A reservation without its client is a passenger name with no
             * account behind it, and without `order_id` there is no way back to
             * the lead that produced it — which is the first thing anyone looks
             * for when a booking is disputed. Both `whenLoaded`, so list
             * responses that do not eager-load stay the same size.
             */
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $this->client->phone,
            ]),
            'order_id' => $this->order_id,

            // Written by setStatus() and never exposed. "When did this trip
            // actually start" is not answerable from `trip_date` alone.
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'deleted_at'      => $this->deleted_at?->toIso8601String(),
        ];
    }
}
