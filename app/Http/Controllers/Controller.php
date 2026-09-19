<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The largest page any list endpoint will serve.
     *
     * There was no ceiling at all on most of them. `BusController`,
     * `PersonController`, `StaffController`, `ReservationController`,
     * `OrderController` and `ClientController` all did `max((int) per_page, 1)`,
     * which floors the value and never caps it, so `?per_page=500000` was a
     * request the server would genuinely try to satisfy: half a million rows
     * hydrated into Eloquent models and serialised through a Resource, from one
     * unauthenticated-in-cost GET. That is an out-of-memory away from taking the
     * process down, and on shared hosting it takes the neighbours with it.
     *
     * 200 rather than 100, because the back office deliberately fetches a large
     * page and paginates in the browser, and dropping it to 100 would silently
     * shrink lists people already use.
     */
    protected const MAX_PER_PAGE = 200;

    /**
     * How many rows this request should get.
     *
     * Clamped rather than validated, on purpose. A `per_page` of 5000 is a
     * caller being greedy or careless, not a caller being wrong, and answering
     * it with a 422 breaks a page that was working for a reason nobody reading
     * the error would guess. It gets 200 and a correct `meta.per_page` telling
     * it so.
     */
    protected function perPage(Request $request, int $default = 25): int
    {
        $requested = (int) $request->input('per_page', $default);

        if ($requested < 1) {
            return $default;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}
