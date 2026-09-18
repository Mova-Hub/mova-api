<?php

namespace App\Http\Controllers\Api\V2\Trip;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\TripRating;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How was your trip?
 *
 * Addressed by ORDER id, not by reservation uuid. `Trip.id` is the order id
 * everywhere in the passenger app and has never been the reservation uuid, so
 * an endpoint keyed on the uuid would need the app to carry a second identifier
 * it does not have. `TripMessageController` made the same call for the same
 * reason and this follows it deliberately.
 *
 * **Every lookup goes through `resolve()`, which scopes on `client_id`.** An id
 * in a URL is a claim, never a permission: without the scope, any signed-in
 * passenger could rate, or read the rating of, somebody else's journey by
 * walking order ids.
 */
class TripRatingController extends Controller
{
    /**
     * What the rating screen needs to render, in one request.
     *
     * Returns the existing rating when there is one, so the screen can show a
     * read-only summary rather than inviting a second submission it would then
     * refuse.
     */
    public function show(Request $request, string $id)
    {
        $reservation = $this->resolve($request->user(), $id);

        return response()->json([
            'status' => true,
            'data' => [
                'can_rate' => $this->canRate($reservation),
                'reason' => $this->refusalReason($reservation),
                'rating' => $reservation->rating?->toWire(),
                /*
                 * Name only. The rating screen shows who ran the trip, and an
                 * employee's phone number and e-mail are not part of "how was
                 * your journey". The trip detail screen is where calling lives.
                 */
                'coordinator' => $reservation->coordinator ? [
                    'name' => $reservation->coordinator->name,
                    'avatar_url' => $reservation->coordinator->avatar_url ?? null,
                ] : null,
                'trip' => [
                    'code' => $reservation->code,
                    'from' => $reservation->from_location,
                    'to' => $reservation->to_location,
                    'completed_at' => $reservation->completed_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function store(Request $request, string $id)
    {
        $reservation = $this->resolve($request->user(), $id);

        if (! $this->canRate($reservation)) {
            /*
             * 422 with the reason, because every refusal here is something the
             * passenger can understand and none of it leaks anything: they
             * already know whether their own trip finished.
             */
            return response()->json([
                'status' => false,
                'message' => $this->refusalReason($reservation) ?? 'Ce trajet ne peut pas être noté.',
            ], 422);
        }

        $data = $request->validate([
            'stars' => ['required', 'integer', 'between:1,5'],
            'punctuality' => ['nullable', 'integer', 'between:1,5'],
            'cleanliness' => ['nullable', 'integer', 'between:1,5'],
            'coordinator_score' => ['nullable', 'integer', 'between:1,5'],
            'comfort' => ['nullable', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:8'],
            'tags.*' => ['string', 'max:40'],
            // 2000 matches the trip message cap, for the same reason: long
            // enough for a real complaint, short enough not to be an upload.
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $rating = TripRating::create([
            'reservation_id' => $reservation->id,
            'order_id' => $reservation->order_id,
            'client_id' => $request->user()->id,
            /*
             * Snapshotted at submission.
             *
             * If ops reassign the trip afterwards, or the coordinator leaves,
             * the rating still records who actually ran it. Reading it through
             * the reservation later would attribute the score to whoever holds
             * the trip now.
             */
            'coordinator_id' => $reservation->coordinator_id,
            'stars' => $data['stars'],
            // `validate()` returns only keys that have rules AND were sent, so
            // every optional field needs `?? null`. This has silently dropped
            // data in this codebase before, see api/AGENTS.md.
            'punctuality' => $data['punctuality'] ?? null,
            'cleanliness' => $data['cleanliness'] ?? null,
            'coordinator_score' => $data['coordinator_score'] ?? null,
            'comfort' => $data['comfort'] ?? null,
            'tags' => $data['tags'] ?? null,
            'comment' => $data['comment'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'data' => ['rating' => $rating->toWire()],
        ], 201);
    }

    /**
     * A trip can be rated once, after it actually finished.
     *
     * `auto_closed_at` excludes trips that a cron closed because nobody pressed
     * Terminer. Asking somebody to rate a journey the system gave up on invites
     * a one-star answer to a question about a trip that may have gone perfectly
     * well, and it would put that score on a coordinator's average.
     */
    private function canRate(Reservation $reservation): bool
    {
        return $reservation->status === 'completed'
            && $reservation->auto_closed_at === null
            && $reservation->rating === null;
    }

    private function refusalReason(Reservation $reservation): ?string
    {
        if ($reservation->rating !== null) {
            return 'Vous avez déjà noté ce trajet.';
        }

        if ($reservation->status !== 'completed') {
            return 'Ce trajet n\'est pas encore terminé.';
        }

        if ($reservation->auto_closed_at !== null) {
            return 'Ce trajet a été clôturé automatiquement et ne peut pas être noté.';
        }

        return null;
    }

    /**
     * The order, scoped to its owner, and the reservation behind it.
     *
     * 404 rather than 403 throughout: distinguishing "not yours" from "does not
     * exist" tells a caller which order ids are real.
     */
    private function resolve(Client $client, string $id): Reservation
    {
        $order = Order::where('client_id', $client->id)
            ->with(['reservation.coordinator', 'reservation.rating'])
            ->findOrFail($id);

        return $order->reservation ?? abort(404, 'Aucun trajet à noter.');
    }
}
