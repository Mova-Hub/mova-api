<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\TripRating;
use Illuminate\Http\Request;

/**
 * The ratings list, for the back office.
 *
 * Read only. A rating is a customer's statement and staff do not edit or delete
 * one: a bad score that can be removed is a score nobody can trust, and the
 * moment ops can tidy the feed the averages beside it stop meaning anything.
 * There is deliberately no update or destroy here.
 */
class TripRatingController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'stars' => ['nullable', 'integer', 'between:1,5'],
            'coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
            'with_comment' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = TripRating::query()
            ->with(['coordinator:id,name', 'client:id,name', 'reservation:id,code,from_location,to_location'])
            ->when($request->filled('stars'), fn ($q) => $q->where('stars', $request->integer('stars')))
            ->when($request->filled('coordinator_id'),
                fn ($q) => $q->where('coordinator_id', $request->integer('coordinator_id')))
            // `boolean()` so "false" from a query string is not truthy, which a
            // plain `filled()` check would make it.
            ->when($request->boolean('with_comment'), fn ($q) => $q->whereNotNull('comment'))
            ->when($request->filled('date_from'),
                fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'),
                fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->latest();

        $ratings = $query->paginate((int) $request->input('per_page', 25));

        /*
         * `->items()`, not the paginator itself.
         *
         * Handing `response()->json()` a paginator serialises the whole
         * LengthAwarePaginator, so the rows land at `data.data` and the caller
         * reads `data.0` as null. The pagination facts it would have carried are
         * in `meta` below, which is the shape this endpoint documents.
         */
        return response()->json([
            'status' => true,
            'data' => $ratings->through(fn (TripRating $r) => [
                'id' => $r->id,
                'order_id' => $r->order_id,
                'code' => $r->reservation?->code,
                'route' => $r->reservation
                    ? $r->reservation->from_location.' vers '.$r->reservation->to_location
                    : null,
                'client' => $r->client?->name,
                'coordinator' => $r->coordinator?->name,
                'stars' => $r->stars,
                'punctuality' => $r->punctuality,
                'cleanliness' => $r->cleanliness,
                'coordinator_score' => $r->coordinator_score,
                'comfort' => $r->comfort,
                'tags' => $r->tags ?? [],
                'comment' => $r->comment,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->items(),
            'meta' => [
                'current_page' => $ratings->currentPage(),
                'last_page' => $ratings->lastPage(),
                'total' => $ratings->total(),
            ],
        ]);
    }
}
