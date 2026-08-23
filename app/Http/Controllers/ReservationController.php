<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Models\Transaction; // Imported
use App\Notifications\ReservationStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; // Imported for transactions
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    // ... [Previous methods: index, store, show, update, destroy, restore, setStatus, syncBuses, attachBus, detachBus, bulkStatus] ...
    // (Keep all existing methods exactly as they were in your provided code)

    // GET /api/reservations?search=...
    public function index(Request $request)
    {
        $q = Reservation::query();
        $trashed = $request->query('trashed');
        if ($trashed === 'with')      $q->withTrashed();
        elseif ($trashed === 'only')  $q->onlyTrashed();

        if ($with = $request->query('with')) {
            $rels = collect(explode(',', $with))->intersect(['buses'])->all();
            if ($rels) $q->with($rels);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $q->where(function ($qq) use ($search) {
                $qq->where('code', 'like', "%{$search}%")
                    ->orWhere('passenger_name', 'like', "%{$search}%")
                    ->orWhere('passenger_phone', 'like', "%{$search}%")
                    ->orWhere('from_location', 'like', "%{$search}%")
                    ->orWhere('to_location', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');
        if ($dateFrom) $q->whereDate('trip_date', '>=', $dateFrom);
        if ($dateTo)   $q->whereDate('trip_date', '<=', $dateTo);

        if ($busId = $request->query('bus_id')) {
            $q->whereHas('buses', fn($bq) => $bq->where('buses.id', $busId));
        }

        $orderBy = in_array($request->query('order_by'), [
            'created_at','updated_at','trip_date','price_total','seats','status'
        ], true) ? $request->query('order_by') : 'trip_date';

        $orderDir = $request->query('order_dir') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($orderBy, $orderDir);

        $perPage = max((int) $request->query('per_page', 15), 1);

        return ReservationResource::collection($q->paginate($perPage));
    }

    public function store(StoreReservationRequest $request)
    {
        $data = $request->validated();
        Log::info('StoreReservation validated data', ['data' => $data]);
        $busIds = $data['bus_ids'] ?? null;
        unset($data['bus_ids']);
        $reservation = Reservation::create($data);
        if (is_array($busIds)) {
            $reservation->buses()->sync(array_values($busIds));
        }
        return (new ReservationResource($reservation->load('buses')))
            ->response()->setStatusCode(201);
    }

    public function show(Reservation $reservation)
    {
        $reservation->load('buses');
        return new ReservationResource($reservation);
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation)
    {
        $data   = $request->validated();
        Log::info('UpdateReservation validated data', ['data' => $data]);
        $busIds = array_key_exists('bus_ids', $data) ? ($data['bus_ids'] ?? null) : null;
        unset($data['bus_ids']);
        $reservation->update($data);
        if ($busIds !== null) {
            $reservation->buses()->sync(array_values($busIds));
        }
        Log::info('UpdateReservation validated payload', [
            'reservation_id' => $reservation->id,
            'data' => $data,
            'bus_ids' => $busIds,
        ]);
        return new ReservationResource($reservation->load('buses'));
    }

    public function destroy(Reservation $reservation)
    {
        $reservation->delete();
        return response()->noContent();
    }

    public function restore(string $reservation)
    {
        $model = Reservation::onlyTrashed()->findOrFail($reservation);
        $model->restore();
        return new ReservationResource($model->load('buses'));
    }

    public function setStatus(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'])],
        ]);

        $newStatus = $validated['status'];

        if ($newStatus === 'in_progress' && $reservation->status !== 'confirmed') {
            return response()->json(['error' => 'La réservation doit être confirmée avant de démarrer.'], 422);
        }
        if ($newStatus === 'completed' && $reservation->status !== 'in_progress') {
            return response()->json(['error' => 'La réservation doit être en cours avant de terminer.'], 422);
        }

        DB::transaction(function () use ($reservation, $newStatus) {
            $updates = ['status' => $newStatus];

            if ($newStatus === 'in_progress' && $reservation->started_at === null) {
                $updates['started_at'] = now();
            }

            if ($newStatus === 'completed' && $reservation->completed_at === null) {
                $updates['completed_at'] = now();
            }

            $reservation->update($updates);

            if ($newStatus === 'cancelled' && $reservation->order) {
                $reservation->order->update(['status' => 'cancelled']);
            }
        });

        // TRIGGER NOTIFICATION AFTER TRANSACTION
        // We load the client relationship if it's not already loaded
        $reservation->loadMissing('client');
        if ($reservation->client) {
            $message = match($newStatus) {
                'in_progress' => "Votre trajet vers {$reservation->to_location} vient de commencer. Bonne route !",
                'completed' => "Vous êtes arrivé à destination ({$reservation->to_location}). Merci d'avoir voyagé avec nous !",
                'cancelled' => "Votre réservation pour {$reservation->to_location} a été annulée.",
                default => "Le statut de votre réservation pour {$reservation->to_location} a été mis à jour."
            };

            $reservation->client->notify(new ReservationStatusUpdated($reservation, $message));
        }

        return new ReservationResource($reservation->fresh(['order']));
    }

    /*
     * These three validated bus ids as `uuid`.
     *
     * `reservations` uses HasUuids, `buses` does not — `buses.id` is a plain
     * auto-increment bigint (2025_10_20_023855_create_buses_table). So the rule
     * could never pass and all three endpoints have been permanently broken:
     * assigning a bus to a reservation was impossible through the API.
     *
     * `BusController@bulkStatus` had it right all along with `integer`, which is
     * why bulk operations worked while these did not.
     */

    public function syncBuses(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_ids'   => ['required','array','min:0'],
            'bus_ids.*' => ['integer','distinct','exists:buses,id'],
        ]);
        $reservation->buses()->sync($validated['bus_ids']);
        return new ReservationResource($reservation->load('buses'));
    }

    public function attachBus(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_id' => ['required','integer','exists:buses,id'],
        ]);
        $reservation->buses()->syncWithoutDetaching([$validated['bus_id']]);
        return new ReservationResource($reservation->load('buses'));
    }

    public function detachBus(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_id' => ['required','integer','exists:buses,id'],
        ]);
        $reservation->buses()->detach($validated['bus_id']);
        return new ReservationResource($reservation->load('buses'));
    }

    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids'    => ['required','array','min:1'],
            'ids.*'  => ['uuid','exists:reservations,id'],
            'status' => ['required', Rule::in(['pending','confirmed','cancelled'])],
        ]);
        $count = Reservation::whereIn('id', $validated['ids'])
            ->update(['status' => $validated['status']]);
        return response()->json(['updated' => $count]);
    }

    // -------------------------------------------------------------------------
    // NEW: PAYMENT ENDPOINT
    // -------------------------------------------------------------------------

    // POST /api/reservations/{reservation}/payment
    public function payment(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'amount'    => ['required', 'numeric', 'min:1'],
            'method'    => ['required', 'string', Rule::in(['cash', 'mobile_money', 'bank_transfer', 'check'])],
            'note'      => ['nullable', 'string'],
            'reference' => ['required_unless:method,cash', 'nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($reservation, $validated) {
            Transaction::create([
                'reservation_id' => $reservation->id,
                'amount'         => $validated['amount'],
                'method'         => $validated['method'],
                'reference'      => $validated['reference'] ?? null,
                'note'           => $validated['note'] ?? null,
                'status'         => 'completed',
            ]);

            $totalPaid = Transaction::where('reservation_id', $reservation->id)
                ->where('status', 'completed')
                ->sum('amount');

            $status = 'pending';
            if ($totalPaid >= $reservation->price_total) {
                $status = 'paid';
            } elseif ($totalPaid > 0) {
                $status = 'pending';
            }

            $reservation->update(['payment_status' => $status]);
        });

        // TRIGGER PAYMENT NOTIFICATION
        $reservation->loadMissing('client');
        if ($reservation->client) {
            $formattedAmount = number_format($validated['amount'], 0, ',', ' ');
            $message = "Nous avons bien reçu votre paiement de {$formattedAmount} FCFA pour votre réservation vers {$reservation->to_location}.";

            // Re-use the status updated notification, but pass the payment message
            $reservation->client->notify(new ReservationStatusUpdated($reservation, $message));
        }

        return new ReservationResource($reservation->refresh());
    }



}
