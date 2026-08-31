<?php

namespace App\Events;

use App\Models\TripMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody said something on a trip.
 *
 * `ShouldBroadcastNow` for the same reason as `TripPositionUpdated`: a queued
 * message would sit behind whatever else is in the queue and arrive after the
 * conversation had moved on. A chat message that is thirty seconds late is not
 * a chat.
 *
 * The same two channels as positions, because the two audiences hold different
 * keys for one trip. The passenger app is order-centric and has never been
 * given the reservation UUID, so it gets `trip.{order}`; the field app and the
 * back office work in reservations and get `reservation.{uuid}`. Both channels
 * are already authorised in `routes/channels.php`, and both authorisations were
 * written to branch on model type, so a message cannot reach a client who does
 * not own the order or a staff member with no claim on the reservation.
 *
 * A reservation created at a counter has no order, so the first channel is
 * omitted rather than emitted as `trip.` with nothing after it, which would be
 * a channel anybody could guess.
 */
class TripMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TripMessage $message) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $reservation = $this->message->reservation;

        $channels = [new PrivateChannel("reservation.{$reservation->id}")];

        if ($reservation->order_id) {
            $channels[] = new PrivateChannel("trip.{$reservation->order_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // The same shape the REST endpoint returns, so the app has one parser
        // and a socket message is indistinguishable from a fetched one.
        return ['message' => $this->message->toWire()];
    }
}
