<?php

namespace App\Http\Controllers\Api\V2\Trip;

use App\Events\TripMessageSent;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\TripMessage;
use App\Notifications\NewTripMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * The passenger's half of the trip conversation.
 *
 * The coordinator's half is `Field\MissionMessageController`, behind the
 * `field` gate. Two controllers rather than one shared endpoint, because the
 * two callers authenticate as different models, are authorised by completely
 * different rules, and must not be able to reach each other's scope by
 * changing a parameter. A single controller branching on `instanceof` is one
 * missing branch away from letting a passenger post as the coordinator.
 *
 * Addressed by ORDER id, matching every other route the app calls: `Trip.id` is
 * the order id throughout the app, and it has never been given the
 * reservation's UUID.
 */
class TripMessageController extends Controller
{
    /**
     * The thread, oldest first.
     *
     * Reading also marks the coordinator's messages as read, because there is
     * no separate "seen" call the app could forget to make, and a thread you
     * have open is a thread you have read.
     */
    public function index(Request $request, string $id)
    {
        /** @var Client $client */
        $client = $request->user();
        $reservation = $this->resolve($client, $id);

        $messages = TripMessage::where('reservation_id', $reservation->id)
            ->with('sender')
            ->orderBy('created_at')
            // A ceiling, so a long-running trip cannot make this endpoint
            // return an unbounded document. Oldest are dropped, not newest.
            ->latest('id')
            ->limit(200)
            ->get()
            ->sortBy('id')
            ->values();

        TripMessage::where('reservation_id', $reservation->id)
            // Only the OTHER side's messages. Marking your own as read is
            // meaningless and would tell the coordinator you had read yourself.
            ->where('sender_type', '!=', Client::class)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => true,
            'data' => [
                'messages' => $messages->map->toWire()->values(),
                /*
                 * Who the passenger is talking to.
                 *
                 * Name, avatar and phone only. The same three fields the
                 * tracking endpoint exposes, and for the same reason: this is
                 * a customer's phone, and nothing else about the employee
                 * belongs on it.
                 */
                'coordinator' => $reservation->coordinator ? [
                    'name' => $reservation->coordinator->name,
                    'phone' => $reservation->coordinator->phone,
                    'avatar_url' => $reservation->coordinator->avatar_url,
                ] : null,
                // The app disables the composer on this rather than guessing
                // from the trip status, which it would get wrong for a trip
                // that finished early.
                'can_send' => $this->canSend($reservation),
            ],
        ]);
    }

    public function store(Request $request, string $id)
    {
        /** @var Client $client */
        $client = $request->user();
        $reservation = $this->resolve($client, $id);

        if (! $this->canSend($reservation)) {
            return response()->json([
                'status' => false,
                'message' => 'Cette conversation est fermée.',
            ], 422);
        }

        $data = $request->validate([
            // 2000 is generous for a chat and small enough that the column and
            // the push payload stay sane. Not nullable: an empty message is a
            // mis-tap, not a thing to store.
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $message = TripMessage::create([
            'reservation_id' => $reservation->id,
            'sender_type' => Client::class,
            'sender_id' => $client->id,
            'body' => trim($data['body']),
        ]);

        $message->setRelation('sender', $client);
        $message->setRelation('reservation', $reservation);

        broadcast(new TripMessageSent($message))->toOthers();

        /*
         * Push to the coordinator, if there is one.
         *
         * After the write, never inside a transaction, and tolerant of there
         * being nobody assigned: an unassigned reservation still accepts
         * messages, because ops read them from the back office and a passenger
         * being told "there is nobody to talk to" is worse than a message that
         * waits.
         */
        if ($reservation->coordinator) {
            Notification::send($reservation->coordinator, new NewTripMessage($message));
        }

        return response()->json([
            'status' => true,
            'data' => ['message' => $message->toWire()],
        ], 201);
    }

    /**
     * Can this thread still be written to?
     *
     * Closed once the trip is finished or called off. The conversation stays
     * READABLE for good, because it is the record of what was agreed, but a
     * message sent to a coordinator who finished the trip last Tuesday reaches
     * nobody, and letting somebody send it is worse than telling them plainly.
     */
    private function canSend(Reservation $reservation): bool
    {
        return in_array($reservation->status, ['pending', 'confirmed', 'in_progress'], true);
    }

    /**
     * Resolves an order id to a reservation the caller actually owns.
     *
     * The `client_id` scope IS the authorisation. `findOrFail` on the id alone
     * would let any signed-in passenger read, and post into, the conversation
     * on a stranger's booking.
     */
    private function resolve(Client $client, string $id): Reservation
    {
        $order = Order::where('client_id', $client->id)
            ->with('reservation.coordinator')
            ->findOrFail($id);

        // 404, not 422. A trip with no reservation has no conversation, and
        // distinguishing the two states here would tell a caller which order
        // ids exist.
        return $order->reservation ?? abort(404, 'Aucune conversation pour ce trajet.');
    }
}
