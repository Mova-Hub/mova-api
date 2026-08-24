<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A charter request, for the back-office.
 *
 * **This did not exist.** `OrderController` returned the raw Eloquent model
 * from `show()` and a raw paginator from `index()`, which meant three things:
 * whatever `$fillable`/`$casts` happened to produce was the contract, the list
 * response had no `meta` block (so the back-office's pagination was reading a
 * shape that was never sent), and adding a column to `orders` silently changed
 * the API.
 *
 * Not to be confused with `OrderHistoryResource`, which is the MOBILE shape —
 * client-facing, itinerary-centric, and deliberately hides internal notes.
 * This one is the staff view: it shows what the client cannot see.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,

            'event_type' => $this->event_type,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'waypoints' => $this->waypoints ?? [],
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,

            'pickup_date' => $this->pickup_date?->toDateString(),
            'pickup_time' => $this->pickup_time,
            'return_date' => $this->return_date?->toDateString(),
            'return_time' => $this->return_time,

            'passengers' => $this->passengers,
            'fleet_requirements' => $this->fleet_requirements ?? [],
            'quoted_total' => $this->quoted_total !== null ? (float) $this->quoted_total : null,

            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'status' => $this->status,

            // Staff-only, and the reason this is not OrderHistoryResource.
            'internal_notes' => $this->internal_notes,

            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $this->client->phone,
                'email' => $this->client->email,
            ]),

            /*
             * The reservation this lead became.
             *
             * Without it an order detail page is a dead end: the single most
             * common question about a converted order is "which booking is it
             * now", and the answer lived one relation away and was never sent.
             */
            'reservation' => $this->whenLoaded('reservation', fn () => $this->reservation ? [
                'id' => (string) $this->reservation->id,
                'code' => $this->reservation->code,
                'status' => $this->reservation->status,
                'payment_status' => $this->reservation->payment_status,
                'price_total' => $this->reservation->price_total !== null
                    ? (float) $this->reservation->price_total
                    : null,
                'buses' => $this->reservation->relationLoaded('buses')
                    ? $this->reservation->buses->map(fn ($b) => [
                        'id' => (string) $b->id,
                        'plate' => $b->plate,
                        'type' => $b->type,
                    ])
                    : [],
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
