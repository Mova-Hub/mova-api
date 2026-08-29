<?php

namespace App\Events;

use App\Models\ReservationPosition;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The convoy moved.
 *
 * Fired every time the coordinator's phone reports a fix, and delivered to the
 * passenger's map over Reverb.
 *
 * **`ShouldBroadcastNow`, not `ShouldBroadcast`.** The queued variant would put
 * a position behind whatever else is in the queue — a batch of assignment
 * e-mails, a PDF invoice — and arrive after the bus had already turned the
 * corner. A position that is thirty seconds late is not tracking. It is also
 * cheap to send synchronously: one small payload to an already-open socket.
 *
 * **Two channels, because the two audiences hold different keys for the same
 * trip.** The passenger app is entirely order-centric — `Trip.id` IS the order
 * id, and it has never been given the reservation's UUID — so it subscribes to
 * `trip.{order}`. Staff work in reservations, so the back-office gets
 * `reservation.{uuid}`. Broadcasting to both costs one extra publish and saves
 * teaching the app a second identifier for a thing it already has.
 *
 * A reservation created at a counter has no order, so the first channel is
 * omitted rather than emitted as `trip.` with nothing after it — which would be
 * a channel any client could guess.
 */
class TripPositionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ReservationPosition $position) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $reservation = $this->position->reservation;

        $channels = [new PrivateChannel("reservation.{$reservation->id}")];

        if ($reservation->order_id) {
            $channels[] = new PrivateChannel("trip.{$reservation->order_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'position.updated';
    }

    /**
     * What goes over the wire.
     *
     * Deliberately NOT the whole model. `user_id` would name the employee
     * driving to a customer's phone, and `accuracy`/`speed` are diagnostics the
     * map does not draw. Only what is needed to move a marker.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lat'         => (float) $this->position->lat,
            'lng'         => (float) $this->position->lng,
            'heading'     => $this->position->heading !== null ? (float) $this->position->heading : null,
            // The DEVICE's clock — see the migration. A client showing "mis à
            // jour il y a 3s" for a fix taken four minutes ago in a dead zone
            // is worse than showing the truth.
            'recorded_at' => $this->position->recorded_at?->toIso8601String(),
        ];
    }
}
