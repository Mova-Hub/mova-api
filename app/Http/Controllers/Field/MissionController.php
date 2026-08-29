<?php

namespace App\Http\Controllers\Field;

use App\Events\TripPositionUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\MissionResource;
use App\Models\Reservation;
use App\Models\ReservationPosition;
use App\Notifications\ReservationStatusUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The field app's whole world.
 *
 * A coordinator's reservations, and the four things they do to one: read it,
 * start it, report where the convoy is, finish it.
 *
 * **Every lookup goes through `mission()`, which scopes to `coordinator_id`.**
 * The `field` middleware answers "may this person use the app at all" — it does
 * NOT answer "is this mission theirs". A controller who checks Pass cards passes
 * the same gate as a coordinator, and an id in a URL is a claim rather than a
 * permission. Without the scope, any field account could read every client's
 * name and phone number by walking reservation ids.
 *
 * Deliberately not inside the `staff` route group: that group is the
 * back-office, and putting a bus controller in it would hand them the clients
 * list and the payments ledger.
 */
class MissionController extends Controller
{
    /**
     * My missions.
     *
     * Everything still to run, soonest first, plus what finished recently —
     * because "what did I do yesterday" is a real question and a coordinator
     * whose list empties at midnight assumes the app has lost their work.
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:confirmed,in_progress,completed,cancelled',
        ]);

        $missions = Reservation::query()
            ->where('coordinator_id', $request->user()->id)
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->input('status')),
                /*
                 * The default view: everything live, plus the last week of
                 * history. A `completed` trip from March is not the field app's
                 * problem, and shipping it to a phone that gets left on buses is
                 * a client list nobody asked for.
                 */
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereIn('status', ['confirmed', 'in_progress'])
                    ->orWhere(fn ($recent) => $recent
                        ->where('status', 'completed')
                        ->where('completed_at', '>=', now()->subWeek()))),
            )
            ->with('buses')
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderBy('trip_date')
            ->paginate((int) $request->input('per_page', 25));

        return MissionResource::collection($missions);
    }

    public function show(Request $request, string $reservation)
    {
        return new MissionResource(
            $this->mission($request, $reservation)->load(['buses.driver'])
        );
    }

    /**
     * The convoy is rolling.
     *
     * Goes through the same state machine `setStatus` uses, so a mission cannot
     * be started twice or started after it finished — and `started_at` records
     * when it ACTUALLY left, which is the figure nobody could produce before.
     */
    public function start(Request $request, string $reservation)
    {
        $mission = $this->mission($request, $reservation);

        if (! $mission->canTransitionTo('in_progress')) {
            return response()->json([
                'status'  => false,
                'message' => 'Cette mission ne peut pas être démarrée dans son état actuel.',
            ], 422);
        }

        $mission->update([
            'status'     => 'in_progress',
            'started_at' => $mission->started_at ?? now(),
        ]);

        $this->tellTheClient($mission, "Votre véhicule est en route vers {$mission->from_location}.");

        return new MissionResource($mission->fresh(['buses']));
    }

    public function complete(Request $request, string $reservation)
    {
        $mission = $this->mission($request, $reservation);

        if (! $mission->canTransitionTo('completed')) {
            return response()->json([
                'status'  => false,
                'message' => 'Démarrez la mission avant de la terminer.',
            ], 422);
        }

        $mission->update([
            'status'       => 'completed',
            'completed_at' => $mission->completed_at ?? now(),
        ]);

        $this->tellTheClient(
            $mission,
            "Vous êtes arrivé à {$mission->to_location}. Merci d’avoir voyagé avec Mova !"
        );

        return new MissionResource($mission->fresh(['buses']));
    }

    /**
     * Where the convoy is.
     *
     * Accepts a BATCH, and that is not an optimisation — it is the offline case.
     * A coordinator on the Brazzaville–Pointe-Noire road loses signal for
     * twenty minutes and the app queues fixes; sending them one request at a
     * time on reconnect would be forty round trips and would still lose the
     * order. The device's own `recorded_at` is what keeps the trail honest.
     *
     * Only while the trip is running: a coordinator's location before it starts
     * or after it ends is their own business, and refusing to store it is
     * cheaper than remembering to delete it.
     */
    public function position(Request $request, string $reservation)
    {
        $mission = $this->mission($request, $reservation);

        if ($mission->status !== 'in_progress') {
            return response()->json([
                'status'  => false,
                'message' => 'La mission n’est pas en cours.',
            ], 422);
        }

        $data = $request->validate([
            'positions'                => 'required|array|min:1|max:120',
            'positions.*.lat'          => 'required|numeric|between:-90,90',
            'positions.*.lng'          => 'required|numeric|between:-180,180',
            'positions.*.heading'      => 'nullable|numeric|between:0,360',
            'positions.*.speed'        => 'nullable|numeric|min:0|max:400',
            'positions.*.accuracy'     => 'nullable|numeric|min:0|max:100000',
            'positions.*.recorded_at'  => 'required|date',
            // Null = the convoy. Present only once driver devices exist; see the
            // migration. Constrained to a bus actually on THIS reservation.
            'positions.*.bus_id'       => 'nullable|integer',
        ]);

        $attachedBusIds = $mission->buses()->pluck('buses.id')->all();

        $rows = collect($data['positions'])
            ->map(function (array $p) use ($mission, $request, $attachedBusIds) {
                $busId = $p['bus_id'] ?? null;

                return [
                    'reservation_id' => $mission->id,
                    // Silently dropped rather than rejected if it names a vehicle
                    // that is not on this trip: the fix is still true about the
                    // convoy, and failing the whole batch would lose the lot.
                    'bus_id'      => in_array($busId, $attachedBusIds, true) ? $busId : null,
                    'user_id'     => $request->user()->id,
                    'lat'         => $p['lat'],
                    'lng'         => $p['lng'],
                    'heading'     => $p['heading'] ?? null,
                    'speed'       => $p['speed'] ?? null,
                    'accuracy'    => $p['accuracy'] ?? null,
                    'recorded_at' => CarbonImmutable::parse($p['recorded_at']),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            })
            ->sortBy('recorded_at')
            ->values();

        DB::table('reservation_positions')->insert($rows->all());

        /*
         * Broadcast the NEWEST fix only.
         *
         * A flushed queue of forty positions is history; the map wants one dot.
         * Emitting all forty would animate the bus replaying the last twenty
         * minutes at socket speed.
         */
        $latest = ReservationPosition::where('reservation_id', $mission->id)
            ->latest('recorded_at')
            ->first();

        if ($latest) {
            // `setRelation`, so the event's `broadcastOn()` does not re-query the
            // reservation it was just handed.
            $latest->setRelation('reservation', $mission);
            TripPositionUpdated::dispatch($latest);
        }

        return response()->json([
            'status'   => true,
            'accepted' => $rows->count(),
        ], 201);
    }

    /**
     * One mission, or a 404.
     *
     * `firstOrFail` on a query already scoped to the caller — never `find()`
     * followed by a check, which leaks existence through timing and through the
     * difference between 403 and 404. Somebody else's mission should look like
     * no mission at all.
     */
    private function mission(Request $request, string $id): Reservation
    {
        return Reservation::query()
            ->where('coordinator_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * Keep the passenger informed, without letting it break the field app.
     *
     * A coordinator standing beside a coach must be able to start the trip when
     * the mail server is down. The status change is already committed; failing
     * the request would have them tap Démarrer again on a mission that had
     * already started.
     */
    private function tellTheClient(Reservation $mission, string $message): void
    {
        $mission->loadMissing('client');

        if (! $mission->client) {
            return;
        }

        try {
            $mission->client->notify(new ReservationStatusUpdated($mission, $message));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
