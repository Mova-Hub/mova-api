<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User; // <-- Import the User model
use App\Notifications\OrderStatusUpdated;
use App\Notifications\NewOrderAdminNotification;
use App\Services\TripQuoteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\Client;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderRequestController extends Controller
{
    /**
     * Minimum turnaround between the outbound and return legs.
     *
     * The same vehicle and crew serve both, so a return booked an hour after
     * departure describes a dispatch that cannot physically happen. Mirrors
     * MIN_RETURN_GAP_HOURS in mobile/src/features/booking/constants.ts — if one
     * moves, move the other.
     */
    private const MIN_RETURN_GAP_HOURS = 3;

    /** A request still needs a human to read, price and crew it. */
    private const MIN_LEAD_TIME_HOURS = 2;

    public function __construct(private TripQuoteService $quotes) {}

    public function store(Request $request)
    {
        /** @var Client $client */
        $client = $request->user();

        $vehicleTypes = array_keys(config('pricing.vehicles'));
        $seatMap      = config('booking.vehicle_seats', []);

        $data = $request->validate([
            'event_type'   => ['required', 'string', Rule::in(array_keys(config('pricing.events')))],
            'from_city'    => 'required|string|max:255',
            'to_city'      => 'required|string|max:255',
            'waypoints'    => 'nullable|array|max:25',
            'waypoints.*.lat' => 'nullable|numeric|between:-90,90',
            'waypoints.*.lng' => 'nullable|numeric|between:-180,180',
            'distance_km'  => 'nullable|numeric|min:0|max:2000',
            'date'         => 'required|date',
            // H:i, so "25:00" and "soon" are rejected before they reach dispatch.
            'time'         => ['required', 'date_format:H:i'],
            // Optional return leg. `after_or_equal` rather than `after` so a
            // same-day round trip (school outing, morning-to-evening) is valid.
            'return_date'  => 'nullable|date|after_or_equal:date',
            'return_time'  => ['nullable', 'date_format:H:i', 'required_with:return_date'],
            'passengers'   => 'required|integer|min:1|max:300',
            // Keys are checked below; `array` alone would accept {"jet": 4}.
            'fleet'        => 'required|array|min:1',
            'fleet.*'      => 'integer|min:1|max:50',
            'contact_name' => 'required|string|max:255',
            'phone'        => 'required|string|max:32',
        ], [
            'return_date.after_or_equal' => 'La date de retour ne peut pas précéder la date de départ.',
            'return_time.required_with'  => 'Indiquez l’heure du retour.',
            'time.date_format'           => 'L’heure de départ doit être au format HH:MM.',
            'return_time.date_format'    => 'L’heure de retour doit être au format HH:MM.',
        ]);

        /*
         * Cross-field rules.
         *
         * These are the same checks the app runs to gate its "Continuer"
         * button. They are repeated here because the app's copy is a courtesy
         * and this one is the rule: nothing stops a request being posted
         * directly, and an unschedulable order costs the ops team a phone call
         * to unpick.
         */
        $errors = [];

        foreach (array_keys($data['fleet']) as $type) {
            if (! in_array($type, $vehicleTypes, true)) {
                $errors['fleet'][] = "Type de véhicule inconnu : {$type}.";
            }
        }

        $departure = Carbon::parse("{$data['date']} {$data['time']}");

        if ($departure->lt(now()->addHours(self::MIN_LEAD_TIME_HOURS))) {
            $errors['date'][] = sprintf(
                'Prévoyez au moins %d h avant le départ pour organiser les véhicules.',
                self::MIN_LEAD_TIME_HOURS
            );
        }

        if (! empty($data['return_date']) && ! empty($data['return_time'])) {
            $return = Carbon::parse("{$data['return_date']} {$data['return_time']}");

            if ($return->lt($departure)) {
                $errors['return_time'][] = 'Le retour ne peut pas précéder le départ.';
            } elseif ($departure->diffInMinutes($return) < self::MIN_RETURN_GAP_HOURS * 60) {
                $errors['return_time'][] = sprintf(
                    'Laissez au moins %d h entre l’aller et le retour.',
                    self::MIN_RETURN_GAP_HOURS
                );
            }
        }

        // Capacity. Seats are read from config, never from the request — a
        // client claiming its Hiace seats 200 must not be believed.
        if (empty($errors['fleet'])) {
            $seats = 0;
            foreach ($data['fleet'] as $type => $count) {
                $seats += ((int) ($seatMap[$type] ?? 0)) * (int) $count;
            }

            if ($seats > 0 && $data['passengers'] > $seats) {
                $errors['fleet'][] = sprintf(
                    'Capacité insuffisante : %d places pour %d personnes.',
                    $seats,
                    $data['passengers']
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /*
         * Price is RECOMPUTED here, never read from the request.
         *
         * The app shows an estimate from POST /app/v1/quote before submitting.
         * Storing that number as sent would make the order total a client-
         * controlled field. This re-derives it from the same service, over the
         * same waypoints, and the Directions call is a cache hit from the
         * quote the app just requested — so it is accurate without being slow.
         */
        $quote = null;
        $located = collect($data['waypoints'] ?? [])
            ->filter(fn ($p) => isset($p['lat'], $p['lng']))
            ->map(fn ($p) => ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']])
            ->values()
            ->all();

        if (count($located) >= 2) {
            try {
                $quote = $this->quotes->quote(
                    waypoints: $located,
                    fleet:     $data['fleet'],
                    event:     $data['event_type'],
                    roundTrip: ! empty($data['return_date']),
                    hintKm:    isset($data['distance_km']) ? (float) $data['distance_km'] : null,
                );
            } catch (InvalidArgumentException $e) {
                // An unpriceable order is still a lead worth capturing — the
                // team quotes it by hand rather than losing the request.
                $quote = null;
            }
        }

        $order = Order::create([
            'client_id'          => $client->id,
            'event_type'         => $data['event_type'],
            'origin'             => $data['from_city'],
            'destination'        => $data['to_city'],
            'waypoints'          => $data['waypoints'] ?? null,
            'distance_km'        => $quote['distance_km'] ?? ($data['distance_km'] ?? null),
            'pickup_date'        => $data['date'],
            'pickup_time'        => $data['time'],
            'return_date'        => $data['return_date'] ?? null,
            'return_time'        => $data['return_time'] ?? null,
            'passengers'         => $data['passengers'],
            'quoted_total'       => $quote['total'] ?? null,
            'fleet_requirements' => $data['fleet'],
            'contact_name'       => $data['contact_name'],
            'contact_phone'      => $data['phone'],
            'status'             => 'pending'
        ]);


        // 1. SECURE ADMIN & AGENT NOTIFICATION

        // Fetch all staff members who are admins or agents AND are currently active
        $staffMembers = User::whereIn('role', ['admin', 'agent'])
            ->where('status', 'active')
            ->get();

        if ($staffMembers->isNotEmpty()) {
            // Notification::send() automatically loops through the collection
            // sending both the email and saving a database record for EACH user securely.
            Notification::send($staffMembers, new NewOrderAdminNotification($order));
        } else {
            // Fallback Security: If for some reason there are 0 active admins in the DB,
            // we still send the lead to the master email so it doesn't get lost.
            Notification::route('mail', 'reservation@mova-mobility.com')
                ->notify(new NewOrderAdminNotification($order));
        }


        // 2. CLIENT NOTIFICATION

        $client->notify(new OrderStatusUpdated(
            $order,
            "Nous avons bien reçu votre commande pour {$data['to_city']}. Notre équipe la valide et vous envoie la facture."
        ));

        return response()->json([
            'status' => true,
            'message' => 'Commande enregistrée.',
            'id' => $order->id,
            'quoted_total' => $order->quoted_total,
        ], 201);
    }
}
