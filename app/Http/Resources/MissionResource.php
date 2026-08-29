<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reservation as the coordinator holding the phone needs it.
 *
 * Not `ReservationResource`. That one serves the back-office and carries the
 * price, the payment status and the client's account link — a coordinator
 * gathering three coaches at dawn needs none of it, and money on a screen used
 * in public is a liability, not a feature.
 *
 * What it does carry is what the job needs: where, when, who to call, which
 * plates to look for, and whether the trip has started. Ordered the way the
 * work happens.
 *
 * @mixin \App\Models\Reservation
 */
class MissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'   => (string) $this->id,
            'code' => $this->code,

            'status' => $this->status,
            /*
             * The two things the detail screen's buttons are gated on, decided
             * HERE rather than in the app.
             *
             * The rules live in `Reservation::canTransitionTo()`, and an app
             * that re-implements them is an app that disagrees with the server
             * the first time either changes — offering a Démarrer button that
             * returns 422 when tapped.
             */
            'can_start'    => $this->canTransitionTo('in_progress'),
            'can_complete' => $this->canTransitionTo('completed'),

            'from' => $this->from_location,
            'to'   => $this->to_location,

            'trip_date'   => $this->trip_date?->toIso8601String(),
            'return_date' => $this->return_date?->toIso8601String(),

            'passengers'  => $this->passengers !== null ? (int) $this->passengers : null,
            'seats'       => (int) $this->seats,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'event'       => $this->event,

            /*
             * The client's name and number, and nothing else about them.
             *
             * A coordinator's first action is to call. Their e-mail, their
             * account id and their other bookings are not part of this job.
             */
            'contact' => [
                'name'  => $this->passenger_name,
                'phone' => $this->passenger_phone,
            ],

            'waypoints' => $this->waypoints,

            'vehicles' => $this->whenLoaded('buses', fn () => $this->buses->map(fn ($bus) => [
                'id'       => (string) $bus->id,
                'plate'    => $bus->plate,
                'type'     => $bus->type,
                'capacity' => $bus->capacity !== null ? (int) $bus->capacity : null,
                // The driver's number, so a late vehicle is one tap away. This
                // is the coordinator's actual job.
                'driver'   => $bus->driver ? [
                    'name'  => $bus->driver->name,
                    'phone' => $bus->driver->phone,
                ] : null,
            ])),

            'started_at'   => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
