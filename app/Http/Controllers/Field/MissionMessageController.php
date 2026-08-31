<?php

namespace App\Http\Controllers\Field;

use App\Events\TripMessageSent;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\TripMessage;
use App\Models\User;
use App\Notifications\NewTripMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * The coordinator's half of the trip conversation.
 *
 * Separate from `Api\V2\Trip\TripMessageController` on purpose. The two callers
 * authenticate as different models and are authorised by completely different
 * rules, and a single controller branching on `instanceof` is one missing
 * branch away from letting a passenger post as the coordinator.
 *
 * Behind the `field` gate, and then scoped to `coordinator_id` on top of it.
 * The gate answers "may this person use the field app at all"; it does NOT
 * answer "is this their mission". A controller who inspects Pass cards passes
 * the gate and must still not be able to read a charter conversation, which is
 * what the scope below enforces. Same rule as every other route in this group.
 */
class MissionMessageController extends Controller
{
    public function index(Request $request, string $reservation)
    {
        $mission = $this->resolve($request->user(), $reservation);

        $messages = TripMessage::where('reservation_id', $mission->id)
            ->with('sender')
            ->latest('id')
            ->limit(200)
            ->get()
            ->sortBy('id')
            ->values();

        // The passenger's messages, marked read. Never our own.
        TripMessage::where('reservation_id', $mission->id)
            ->where('sender_type', Client::class)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => true,
            'data' => [
                'messages' => $messages->map->toWire()->values(),
                'client' => $mission->client ? [
                    'name' => $mission->client->name,
                    'phone' => $mission->client->phone,
                ] : null,
            ],
        ]);
    }

    public function store(Request $request, string $reservation)
    {
        $mission = $this->resolve($request->user(), $reservation);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        /** @var User $coordinator */
        $coordinator = $request->user();

        $message = TripMessage::create([
            'reservation_id' => $mission->id,
            'sender_type' => User::class,
            'sender_id' => $coordinator->id,
            'body' => trim($data['body']),
        ]);

        $message->setRelation('sender', $coordinator);
        $message->setRelation('reservation', $mission);

        broadcast(new TripMessageSent($message))->toOthers();

        if ($mission->client) {
            Notification::send($mission->client, new NewTripMessage($message));
        }

        return response()->json([
            'status' => true,
            'data' => ['message' => $message->toWire()],
        ], 201);
    }

    /**
     * The mission, if it is this coordinator's.
     *
     * An id in a URL is a claim, never an authorisation.
     */
    private function resolve(User $user, string $reservation): Reservation
    {
        return Reservation::where('id', $reservation)
            ->where('coordinator_id', $user->id)
            ->with('client')
            ->firstOrFail();
    }
}
