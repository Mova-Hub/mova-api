<?php

namespace App\Http\Resources;

use App\Domain\Booking\TripSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $res = $this->reservation;

        // The agreed schedule. See the note beside the date fields below for
        // why this is not read off the order.
        $schedule = TripSchedule::for($this->resource);
        $returnsAt = $res?->return_date ?? $this->return_date;

        return [
            'id' => $this->id,
            'code' => $res?->code,
            'event_type' => $this->event_type,
            'status' => $this->status,
            'reservation_status' => $res ? $res->status : $this->status,  // Use reservation status if converted
            'payment_status' => $res?->payment_status,
            'itinerary' => [
                'from' => $this->origin,
                'to' => $this->destination,
                // Prioritize the finalized Reservation data, fallback to the original Order data
                'waypoints' => $res ? $res->waypoints : $this->waypoints,
                'distance_km' => $res ? (float) $res->distance_km : (float) $this->distance_km,
                /*
                 * The AGREED dates, not the requested ones.
                 *
                 * These read `$schedule`, which prefers `reservations.trip_date`
                 * and `reservations.return_date` over the order's own columns.
                 * The order carries what the client asked for on the form and
                 * is never rewritten; the reservation carries what ops
                 * confirmed and is what they edit when a trip moves.
                 *
                 * This resource already preferred the reservation for
                 * `waypoints` and `distance_km` a few lines above. The dates
                 * were the inconsistency, and they matter more: `date_iso` is
                 * what the app sorts on and what decides A venir versus
                 * Historique, so a rescheduled trip was filed under the wrong
                 * one and ordered by a date that had stopped being true.
                 */
                'date' => $schedule?->start->translatedFormat('d F Y'), // "15 Janvier 2026"
                // The formatted date above cannot be parsed, compared or
                // sorted, so the app had no way to tell an upcoming trip from a
                // past one. These carry the same instants in a machine form;
                // the display strings stay for the existing web client.
                'date_iso' => $schedule?->start->toDateString(),
                // The clock only when one was actually stated. `allDay` means
                // nobody set an hour, and echoing the order's free text there
                // would put "tot le matin" in a time field.
                'time' => $schedule && ! $schedule->allDay
                    ? $schedule->start->format('H:i')
                    : $this->pickup_time,
                'return_date' => $returnsAt?->translatedFormat('d F Y'),
                'return_date_iso' => $returnsAt?->toDateString(),
                'return_time' => $this->return_time,
            ],
            'passengers' => $this->passengers,
            'vehicles' => $res ? $res->buses->map(fn($bus) => [
                'id' => $bus->id,
                'plate' => $bus->plate,
                'type' => $bus->type,
                'label' => "{$bus->plate} ({$bus->type})",
                // Gestion Pro du Driver (User Model)
                'driver' => $bus->driver ? [
                    'name' => $bus->driver->name,
                    // 'phone' => $bus->driver->phone,
                    'avatar' => $bus->driver->avatar_url ?? null,
                    'license' => $bus->driver->license_no,
                ] : null,
            ]) : [],
            'pricing' => [
                // The confirmed reservation price wins; before conversion, fall
                // back to what the app quoted at submission, so a client is not
                // shown "0 FCFA" for an order they were given a figure for.
                'total' => $res
                    ? (float) $res->price_total
                    : (float) ($this->quoted_total ?? 0),
                'is_estimate' => ! $res && $this->quoted_total !== null,
                'is_paid' => $res?->payment_status === 'paid',
            ],
            'started_at' => $res?->started_at,
            'completed_at' => $res?->completed_at,
            'internal_notes' => $this->internal_notes,
        ];
    }
}
