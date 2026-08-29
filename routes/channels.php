<?php

use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| **Every callback here must branch on the model type.** This API has TWO
| authenticatable models — `Client` (the passenger app) and `User` (manager and
| control) — and Sanctum resolves whichever one owns the presented token. So the
| `$user` argument below is not a `User`; it is "whoever authenticated", and a
| callback that assumes otherwise is the same class of hole `EnsureStaff` exists
| to close. A `Client` has no `role` column at all.
|
| These run behind `auth:sanctum`, wired in bootstrap/app.php — the default
| `withRouting(channels:)` registration puts /broadcasting/auth on the `web`
| guard, which nothing in this system uses.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return $user instanceof User && (int) $user->id === (int) $id;
});

/**
 * A passenger watching their own trip.
 *
 * Keyed on the ORDER id, because that is the only identifier the mobile app
 * holds for a trip — `Trip.id` is the order id throughout the app, and payments
 * are made against `payable_type = order` for the same reason.
 *
 * The `client_id` comparison is the entire authorisation. Without it, any
 * signed-in passenger could subscribe to `trip.{n}` for every n and follow
 * strangers' vehicles around Brazzaville in real time.
 */
Broadcast::channel('trip.{orderId}', function ($user, $orderId) {
    if (! $user instanceof Client) {
        return false;
    }

    return Order::where('id', $orderId)
        ->where('client_id', $user->id)
        ->exists();
});

/**
 * Staff and the assigned coordinator, watching a reservation.
 *
 * Back-office people work in reservations, not orders, so they get the UUID
 * channel. A coordinator is not staff — that is the whole point of the role
 * split — so they are authorised by assignment instead.
 */
Broadcast::channel('reservation.{reservationId}', function ($user, $reservationId) {
    if (! $user instanceof User) {
        return false;
    }

    if ($user->isStaff()) {
        return true;
    }

    return Reservation::where('id', $reservationId)
        ->where('coordinator_id', $user->id)
        ->exists();
});
