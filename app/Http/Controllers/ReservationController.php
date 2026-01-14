<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Models\Transaction; // Imported
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
        // ... existing index code ...
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
        // ... existing store code ...
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
        // ... existing update code ...
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
            'status' => ['required', Rule::in(['pending', 'confirmed', 'cancelled'])],
        ]);

        DB::transaction(function () use ($reservation, $validated) {
            $reservation->update([
                'status' => $validated['status'],
            ]);

            if ($validated['status'] === 'cancelled' && $reservation->order) {
                $reservation->order->update([
                    'status' => 'cancelled',
                ]);
            }
        });

        return new ReservationResource($reservation->fresh(['order']));
    }

    public function syncBuses(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_ids'   => ['required','array','min:0'],
            'bus_ids.*' => ['uuid','distinct','exists:buses,id'],
        ]);
        $reservation->buses()->sync($validated['bus_ids']);
        return new ReservationResource($reservation->load('buses'));
    }

    public function attachBus(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_id' => ['required','uuid','exists:buses,id'],
        ]);
        $reservation->buses()->syncWithoutDetaching([$validated['bus_id']]);
        return new ReservationResource($reservation->load('buses'));
    }

    public function detachBus(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_id' => ['required','uuid','exists:buses,id'],
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
            // Reference required unless paying by cash
            'reference' => ['required_unless:method,cash', 'nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($reservation, $validated) {
            // 1. Create Transaction
            Transaction::create([
                'reservation_id' => $reservation->id,
                'amount'         => $validated['amount'],
                'method'         => $validated['method'],
                'reference'      => $validated['reference'] ?? null,
                'note'           => $validated['note'] ?? null,
                'status'         => 'completed', // Assuming manual entry is always completed
            ]);

            // 2. Update Reservation Payment Status
            // Logic: If total paid >= price_total, mark as paid.
            // For now, simpler logic: manual entry usually implies full or significant payment.
            // We'll verify against the total.

            $totalPaid = Transaction::where('reservation_id', $reservation->id)
                ->where('status', 'completed')
                ->sum('amount'); // Add the current one? No, transaction created above is included if committed.

            // Re-query sum including the new one
            // Note: DB::transaction ensures this reads correctly inside transaction in most engines,
            // but for safety we can just add $validated['amount'] to previous sum if needed.
            // Here, created record is visible.

            $status = 'pending';
            if ($totalPaid >= $reservation->price_total) {
                $status = 'paid';
            } elseif ($totalPaid > 0) {
                $status = 'pending'; // or 'partial' if you add that status later
            }

            $reservation->update(['payment_status' => $status]);
        });

        return new ReservationResource($reservation->refresh());
    }
}
