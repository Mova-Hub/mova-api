<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Support\PerformsAuditedBulkUpdates;
use App\Http\Requests\StoreBusRequest;
use App\Http\Requests\UpdateBusRequest;
use App\Http\Resources\BusResource;
use App\Models\Bus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusController extends Controller
{
    use PerformsAuditedBulkUpdates;

    // GET /api/buses?search=&status=&type=&operator_id=&driver_id=&year_min=&year_max=&service_before=&insurance_before=&per_page=15&with=operator,driver&order_by=created_at&order_dir=desc
    public function index(Request $request)
    {
        $q = Bus::query();

        // eager loads
        if ($with = $request->query('with')) {
            $relations = collect(explode(',', $with))
                ->intersect(['operator','driver', 'conductor'])
                ->all();
            if ($relations) $q->with($relations);
        }

        // search across common fields
        if ($search = trim((string) $request->query('search',''))) {
            $q->where(function($qq) use ($search) {
                $qq->where('plate','like',"%{$search}%")
                   ->orWhere('name','like',"%{$search}%")
                   ->orWhere('model','like',"%{$search}%");
            });
        }

        // filters
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($op = $request->query('operator_id')) {
            $q->where('operator_id', $op);
        }
        if ($drv = $request->query('driver_id')) {
            $q->where('assigned_driver_id', $drv);
        }
        if ($min = $request->query('year_min')) {
            $q->where('year', '>=', (int)$min);
        }
        if ($max = $request->query('year_max')) {
            $q->where('year', '<=', (int)$max);
        }
        if ($svcBefore = $request->query('service_before')) {
            $q->whereDate('last_service_date', '<=', $svcBefore);
        }
        if ($insBefore = $request->query('insurance_before')) {
            $q->whereDate('insurance_valid_until', '<=', $insBefore);
        }

        // ordering
        $orderBy = in_array($request->query('order_by'), [
            'created_at','updated_at','plate','status','type','year','mileage_km'
        ], true) ? $request->query('order_by') : 'created_at';

        $orderDir = $request->query('order_dir') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($orderBy, $orderDir);

        $perPage = max((int)$request->query('per_page', 15), 1);

        return BusResource::collection($q->paginate($perPage));
    }

    // POST /api/buses
    public function store(StoreBusRequest $request)
    {
        $data = $request->validated();
        // ensure UUID id
        // $data['id'] = (string) Str::uuid();
        // defaults
        $data['status'] = $data['status'] ?? 'active';

        $bus = Bus::create($data);

        return new BusResource($bus->load(['operator','driver', 'conductor']));
    }

    // GET /api/buses/{bus}
    public function show(Bus $bus)
    {
        $bus->load(['operator','driver', 'conductor']);
        return new BusResource($bus);
    }

    // PUT/PATCH /api/buses/{bus}
    public function update(UpdateBusRequest $request, Bus $bus)
    {
        $bus->update($request->validated());
        return new BusResource($bus->load(['operator','driver', 'conductor']));
    }

    // DELETE /api/buses/{bus}
    public function destroy(Bus $bus)
    {
        $bus->delete(); // hard delete (you can switch to SoftDeletes if needed)
        return response()->noContent();
    }

    // POST /api/buses/{bus}/status  { status: active|maintenance|inactive }
    public function setStatus(Request $request, Bus $bus)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active','maintenance','inactive'])],
        ]);

        $bus->update(['status' => $validated['status']]);
        return new BusResource($bus);
    }

    // POST /api/buses/{bus}/assign-driver  { user_id: uuid|null }
    public function assignDriver(Request $request, Bus $bus)
    {
        $validated = $request->validate([
            'user_id' => [
                'nullable',
                Rule::exists('users','id')->where(fn($q)=>$q->where('role','driver')),
            ],
        ]);

        $bus->update(['assigned_driver_id' => $validated['user_id'] ?? null]);

        return new BusResource($bus->load('driver'));
    }

    // POST /api/buses/{bus}/assign-conductor  { user_id: uuid|null }
    public function assignConductor(Request $request, Bus $bus)
    {
        $validated = $request->validate([
            'user_id' => [
                'nullable',
                Rule::exists('users','id')->where(fn($q)=>$q->where('role','conductor')),
            ],
        ]);

        $bus->update(['assigned_conductor_id' => $validated['user_id'] ?? null]);

        return new BusResource($bus->load('conductor'));
    }

    // POST /api/buses/{bus}/set-operator  { user_id: uuid|null }
    public function setOperator(Request $request, Bus $bus)
    {
        $validated = $request->validate([
            'user_id' => [
                'nullable',
                Rule::exists('users','id')->where(fn($q)=>$q->whereIn('role',['owner','admin'])),
            ],
        ]);

        $bus->update(['operator_id' => $validated['user_id'] ?? null]);

        return new BusResource($bus->load('operator'));
    }

    // POST /api/buses/bulk-status  { ids: [integer,...], status: active|maintenance|inactive }
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids'    => ['required','array','min:1','max:200'],
            'ids.*'  => ['integer','exists:buses,id'],
            'status' => ['required', Rule::in(['active','maintenance','inactive'])],
        ]);

        // Was a single Builder::update(), which fires no model events and so
        // produced no audit record — see PerformsAuditedBulkUpdates.
        $count = $this->auditedBulkUpdate(
            Bus::whereIn('id', $validated['ids']),
            ['status' => $validated['status']],
            'bus.bulk_status',
            ['ids' => $validated['ids']],
        );

        return response()->json(['updated' => $count]);
    }

    // POST /api/buses/bulk-destroy  { ids: [integer,...] }
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids'   => ['required','array','min:1','max:200'],
            'ids.*' => ['integer','exists:buses,id'],
        ]);

        /*
         * Buses hard-delete, so this is the one bulk action that destroys data
         * outright. The observer's `deleted` hook captures each row's full
         * state on the way out — without it a mass deletion is both
         * unrecoverable and unexplainable.
         */
        $count = $this->auditedBulkDelete(
            Bus::whereIn('id', $validated['ids']),
            'bus.bulk_destroy',
            ['ids' => $validated['ids']],
        );

        return response()->json(['deleted' => $count]);
    }

    // GET /api/buses/{bus}/stats
    public function stats(Bus $bus)
    {
        $reservations = $bus->reservations;

        $byStatus = $reservations->groupBy('status')
            ->map(fn($g) => $g->count())
            ->toArray();

        $byEvent = $reservations->groupBy('event')
            ->filter(fn($g, $k) => !is_null($k) && $k !== '')
            ->map(fn($g) => $g->count())
            ->toArray();

        $recent = $bus->reservations()
            ->orderByDesc('trip_date')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'id'             => $r->id,
                'code'           => $r->code ?? null,
                'status'         => $r->status,
                'trip_date'      => $r->trip_date instanceof \Carbon\Carbon
                    ? $r->trip_date->toIso8601String()
                    : $r->trip_date,
                'price_total'    => (float) ($r->price_total ?? 0),
                'passenger_name' => is_array($r->passenger)
                    ? ($r->passenger['name'] ?? '—')
                    : (is_string($r->passenger) ? $r->passenger : '—'),
            ]);

        return response()->json([
            'total_reservations' => $reservations->count(),
            'total_distance_km'  => (float) $reservations->sum('distance_km'),
            'total_revenue'      => (float) $reservations->sum('price_total'),
            'by_status'          => $byStatus,
            'by_event'           => $byEvent,
            'recent'             => $recent,
        ]);
    }
}
