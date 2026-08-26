<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Audit\Services\RequestFingerprint;
use App\Http\Controllers\Controller;
use App\Http\Resources\Audit\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

/**
 * Reading the audit trail.
 *
 * **Read-only, and there is no write route anywhere in the application.** An
 * audit log an operator can edit or delete proves nothing — the first thing
 * anyone covering their tracks would reach for is the delete button. Rows leave
 * only by ageing out, through `activity:prune`, on a schedule.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor_type' => ['nullable', 'string', 'max:120'],
            'actor_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'subject_id' => ['nullable', 'integer'],
            'request_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'kind' => ['nullable', 'in:mutations,access'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = ActivityLog::query()->latest('id');

        // Mutations and reads are separated by default in the UI, because a
        // hundred invoice views should not push one price change off page one.
        if ($kind = $request->input('kind')) {
            $kind === 'access' ? $query->accessOnly() : $query->mutationsOnly();
        }

        foreach (['action', 'actor_type', 'actor_id', 'subject_type', 'subject_id', 'request_id'] as $field) {
            if (($value = $request->input($field)) !== null && $value !== '') {
                // `action` is a prefix match so `order` finds `order.updated`,
                // `order.created` and so on without listing every verb.
                $field === 'action'
                    ? $query->where('action', 'like', $value . '%')
                    : $query->where($field, $value);
            }
        }

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            // Against the denormalised labels, not a join — which is the whole
            // reason they are stored: entries belonging to a deleted staff
            // member stay searchable.
            $query->where(function ($q) use ($search) {
                $q->where('actor_label', 'like', "%{$search}%")
                  ->orWhere('subject_label', 'like', "%{$search}%");
            });
        }

        return ActivityLogResource::collection(
            $query->paginate((int) $request->input('per_page', 50))
        );
    }

    public function show(int $id)
    {
        return new ActivityLogResource(ActivityLog::findOrFail($id));
    }

    /**
     * Everything the audit entry knows about HOW and FROM WHERE, interpreted.
     *
     * Deliberately a separate endpoint from `show`: the geolocation lookup
     * touches a database file and the user-agent parse runs several hundred
     * regexes, and neither should be able to make the audit record itself slow
     * to load or fail to load.
     *
     * See RequestFingerprint for why nothing here is presented as fact.
     */
    public function fingerprint(int $id, RequestFingerprint $fingerprint)
    {
        $log = ActivityLog::findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => [
                'device' => $fingerprint->device($log->user_agent, $log->declared_client),
                'location' => $fingerprint->location($log->ip),
            ],
        ]);
    }

    /**
     * Everything that happened in one request.
     *
     * The pivot from a Sentry event or a log line into the mutations that
     * request produced — the reason `request_id` exists on all three.
     */
    public function byRequest(string $requestId)
    {
        return ActivityLogResource::collection(
            ActivityLog::where('request_id', $requestId)->orderBy('id')->get()
        );
    }

    /** The distinct verbs present, for populating a filter without hardcoding. */
    public function actions()
    {
        return response()->json([
            'status' => true,
            'data' => ActivityLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
