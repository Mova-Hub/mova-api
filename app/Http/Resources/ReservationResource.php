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
            // Null = one way. Both the price and the vehicle schedule depend on
            // this, so it is a first-class field rather than something to infer.
            'return_date'     => $this->return_date,
            'is_round_trip'   => $this->return_date !== null,
            'from_location'   => $this->from_location,
            'to_location'     => $this->to_location,

            'passenger'       => [
                'name'  => $this->passenger_name,
                'phone' => $this->passenger_phone,
                'email' => $this->passenger_email,
            ],

            // Capacity attached, and head count expected. Two different facts:
            // either alone hides whether everybody has a seat.
            'seats'           => (int) $this->seats,
            'passengers'      => $this->passengers !== null ? (int) $this->passengers : null,
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

            /*
             * Who is delivering this trip.
             *
             * Name and PHONE, no e-mail: the back-office calls a coordinator, it
             * does not write to them, and an address is one more piece of staff
             * PII in a payload the field app also reads.
             *
             * `coordinator_id` is emitted unconditionally so a form can
             * pre-select the current holder even when the relation was not
             * eager-loaded — a picker that shows "aucun" for an assigned
             * reservation is how somebody gets assigned twice.
             */
            'coordinator_id' => $this->coordinator_id,
            'coordinator' => $this->whenLoaded('coordinator', fn () => $this->coordinator ? [
                'id' => $this->coordinator->id,
                'name' => $this->coordinator->name,
                'phone' => $this->coordinator->phone,
                'role' => $this->coordinator->role,
            ] : null),

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
