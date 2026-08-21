<?php

namespace App\Http\Controllers\Api\V2\Payment;

use App\Domain\Payment\PaymentService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Order invoices.
 *
 * Two endpoints, and the split is the security design:
 *
 *  - `link()` is Sanctum-authenticated and scoped to the caller's own orders.
 *    It mints a SIGNED, short-lived URL.
 *  - `show()` is reachable without a token, because a browser opened from the
 *    app sends no Authorization header — but only with a valid signature that
 *    has not expired.
 *
 * The alternative, putting the order id on a public route, would let anyone
 * read every client's itinerary, phone number and price by counting upwards.
 */
class InvoiceController extends Controller
{
    /** Long enough to open and print, short enough that a shared link dies. */
    private const LINK_TTL_MINUTES = 30;

    public function __construct(private PaymentService $payments) {}

    /**
     * Returns a signed URL the app can hand to the system browser.
     */
    public function link(Request $request, int $id)
    {
        // Scoped, not `findOrFail($id)` — an id in the URL is not authorisation.
        $order = Order::where('client_id', $request->user()->id)->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => [
                'url' => URL::temporarySignedRoute(
                    'invoice.show',
                    now()->addMinutes(self::LINK_TTL_MINUTES),
                    ['order' => $order->id],
                ),
                'expires_in' => self::LINK_TTL_MINUTES * 60,
            ],
        ]);
    }

    /**
     * Renders the invoice.
     *
     * HTML, not a PDF binary — deliberately. Generating a PDF server-side would
     * mean a rendering engine whose CSS support is a decade behind, and the app
     * has no native package that can save a file. This document is built to
     * print: one tap through the browser's share sheet produces a proper A4 PDF
     * on both platforms, and the design is entirely ours rather than whatever
     * dompdf can approximate.
     */
    public function show(Request $request, int $order)
    {
        $order = Order::with('reservation.buses')->findOrFail($order);

        $seatMap = config('booking.vehicle_seats', []);
        $vehicleLabels = config('pricing.vehicles', []);

        $total = $this->payments->amountFor($order);
        $isPaid = $this->payments->isPaid($order)
            || $order->reservation?->payment_status === 'paid';

        /*
         * Line items are derived from the fleet requested, and each carries its
         * own share of the total rather than a made-up unit price.
         *
         * Inventing "425 F/km × 12 km" here would produce numbers that do not
         * add up to what the client is being charged: the real tariff includes
         * an event uplift, a mobile-money surcharge and Mova's 25-step
         * rounding, none of which divide neatly per vehicle. An invoice whose
         * lines do not sum to its total is worse than one with fewer lines.
         */
        $fleet = (array) ($order->fleet_requirements ?? []);
        $vehicleCount = array_sum(array_map('intval', $fleet)) ?: 1;

        $lines = [];
        $allocated = 0;
        $index = 0;
        $lastIndex = count($fleet) - 1;

        foreach ($fleet as $type => $count) {
            $count = (int) $count;
            $seats = ($seatMap[$type] ?? 0) * $count;

            // The last line absorbs the rounding remainder, so the column always
            // sums to the total exactly.
            $share = $index === $lastIndex
                ? $total - $allocated
                : (int) round($total * ($count / $vehicleCount));

            $allocated += $share;
            $index++;

            $lines[] = [
                'label' => ($vehicleLabels[$type]['label'] ?? ucfirst((string) $type)),
                'detail' => 'Mise à disposition avec chauffeur',
                'quantity' => $count,
                'seats' => $seats ?: '—',
                'amount' => $this->money($share),
            ];
        }

        if ($lines === []) {
            $lines[] = [
                'label' => 'Transport',
                'detail' => 'Mise à disposition avec chauffeur',
                'quantity' => 1,
                'seats' => '—',
                'amount' => $this->money($total),
            ];
        }

        $waypoints = (array) ($order->waypoints ?? []);
        // Origin and destination are rendered separately; only the middle
        // entries are "stops".
        $stops = collect(array_slice($waypoints, 1, max(0, count($waypoints) - 2)))
            ->map(fn ($w) => $w['label'] ?? null)
            ->filter()
            ->values()
            ->all();

        return response()->view('invoice.order', [
            'order' => $order,
            'reference' => $order->reservation?->code ?? 'MOVA-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            'issuedAt' => CarbonImmutable::now()->translatedFormat('d F Y'),
            'eventLabel' => $this->eventLabel($order->event_type),
            'dateLabel' => $order->pickup_date?->translatedFormat('d F Y') ?? '—',
            'returnLabel' => $order->return_date?->translatedFormat('d F Y'),
            'stops' => $stops,
            'lines' => $lines,
            'distanceLabel' => $order->distance_km ? number_format((float) $order->distance_km, 1, ',', ' ') . ' km' : null,
            'totalLabel' => $this->money($total),
            'isPaid' => $isPaid,
        ]);
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }

    private function eventLabel(?string $eventType): string
    {
        if (! $eventType) {
            return 'Transport';
        }

        // The pricing config knows every valid event; its keys are the same ids
        // the app sends. Anything else is shown tidied rather than hidden.
        $known = array_key_exists($eventType, config('pricing.events', []));

        return $known
            ? ucfirst(str_replace('_', ' ', $eventType))
            : ucfirst(str_replace('_', ' ', $eventType));
    }
}
