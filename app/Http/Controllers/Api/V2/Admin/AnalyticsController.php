<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\Client;
use App\Models\Order;
use App\Models\PassScan;
use App\Models\PassSubscription;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\WalletAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Everything the dashboard shows.
 *
 * **One endpoint per tab**, so opening a tab is one request rather than six.
 * The alternative — one fat `/analytics` returning everything — makes the first
 * paint wait on the slowest query in the system, and most sessions only ever
 * look at the overview.
 *
 * **Every figure is a SQL aggregate.** Not `->get()` then `count()` in PHP:
 * these tables grow, and a dashboard that loads ten thousand rows to display
 * one number is a dashboard that stops working right when the business starts
 * working. The one deliberate exception is the fleet tab, which joins in PHP
 * over a bounded set — see the note there.
 *
 * `DashboardController` stays as it is: `/dash/cards` and `/dash/charts` are
 * live and the overview here supersedes them, but breaking a working endpoint
 * to save a duplicate is not a trade worth making mid-migration.
 */
class AnalyticsController extends Controller
{
    /* ─────────────────────────── Tabs ─────────────────────────── */

    public function overview(Request $request)
    {
        [$start, $end, $prevStart, $prevEnd] = $this->window($request);

        $collected = $this->collectedBetween($start, $end);
        $prevCollected = $this->collectedBetween($prevStart, $prevEnd);

        $orders = Order::whereBetween('created_at', [$start, $end])->count();
        $prevOrders = Order::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $converted = Order::whereBetween('created_at', [$start, $end])
            ->where('status', 'converted')->count();

        $trips = Reservation::whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->count();
        $prevTrips = Reservation::whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$prevStart, $prevEnd])
            ->count();

        return response()->json([
            'range' => $request->input('range', '30d'),
            'window' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'kpis' => [
                'collected' => $this->kpi('Encaissé', $collected, $prevCollected, 'money'),
                'orders' => $this->kpi('Commandes reçues', $orders, $prevOrders, 'number'),
                'conversion' => [
                    'label' => 'Taux de conversion',
                    'value' => $orders > 0 ? round($converted / $orders * 100, 1) : 0.0,
                    'format' => 'percent',
                    // Rates compare in POINTS, not percent-of-percent. Labelled
                    // so the UI can render "+4 pts" rather than "+4 %", which
                    // would mean something else entirely.
                    'delta' => $this->conversionDelta($orders, $converted, $prevStart, $prevEnd),
                    'delta_unit' => 'points',
                ],
                'trips' => $this->kpi('Trajets effectués', $trips, $prevTrips, 'number'),
            ],
            'series' => $this->revenueSeries($start, $end),
            'outstanding' => $this->outstanding(),
        ]);
    }

    public function revenue(Request $request)
    {
        [$start, $end] = $this->window($request);

        return response()->json([
            'series' => $this->revenueSeries($start, $end),

            'by_provider' => Payment::succeededBetween($start, $end)
                ->selectRaw('provider_code, COUNT(*) as count, SUM(amount) as total, SUM(fee_amount) as fees')
                ->groupBy('provider_code')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => [
                    'provider' => $r->provider_code,
                    'count' => (int) $r->count,
                    'total' => (int) $r->total,
                    'fees' => (int) $r->fees,
                ]),

            // app vs back_office is the question this answers: how much of the
            // money now arrives without an agent touching it.
            'by_channel' => Payment::succeededBetween($start, $end)
                ->selectRaw('channel, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('channel')
                ->get()
                ->map(fn ($r) => [
                    'channel' => $r->channel,
                    'count' => (int) $r->count,
                    'total' => (int) $r->total,
                ]),

            'by_payable' => Payment::succeededBetween($start, $end)
                ->selectRaw('payable_type, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('payable_type')
                ->get()
                ->map(fn ($r) => [
                    'type' => class_basename($r->payable_type),
                    'count' => (int) $r->count,
                    'total' => (int) $r->total,
                ]),

            /*
             * Attempt outcomes — the number that says whether a provider is
             * healthy. A success rate sliding from 94% to 60% is the earliest
             * signal that something is wrong with an integration, and it is
             * invisible in a revenue total.
             */
            'attempts' => Payment::whereBetween('created_at', [$start, $end])
                ->where('kind', '!=', 'refund')
                ->selectRaw('provider_code, status, COUNT(*) as count')
                ->groupBy('provider_code', 'status')
                ->get()
                ->groupBy('provider_code')
                ->map(function ($rows, $provider) {
                    $total = $rows->sum('count');
                    $ok = (int) $rows->firstWhere('status', PaymentStatus::Succeeded->value)?->count;

                    return [
                        'provider' => $provider,
                        'total' => (int) $total,
                        'succeeded' => $ok,
                        'success_rate' => $total > 0 ? round($ok / $total * 100, 1) : 0.0,
                    ];
                })
                ->values(),

            'fees_total' => (int) Payment::succeededBetween($start, $end)->sum('fee_amount'),
            'refunds_total' => (int) Payment::where('kind', 'refund')
                ->where('status', PaymentStatus::Succeeded->value)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount'),
            'outstanding' => $this->outstanding(),
        ]);
    }

    public function operations(Request $request)
    {
        [$start, $end] = $this->window($request);

        return response()->json([
            'orders_by_status' => $this->countBy(
                Order::whereBetween('created_at', [$start, $end]), 'status'
            ),

            'orders_by_event' => Order::whereBetween('created_at', [$start, $end])
                ->selectRaw('event_type, COUNT(*) as count')
                ->groupBy('event_type')
                ->orderByDesc('count')
                ->limit(15)
                ->get()
                ->map(fn ($r) => ['event' => $r->event_type, 'count' => (int) $r->count]),

            'reservations_by_status' => $this->countBy(
                Reservation::whereNull('deleted_at')->whereBetween('created_at', [$start, $end]),
                'status'
            ),

            /*
             * The funnel, in the only order that means anything: every stage
             * counts orders CREATED in the window, so a lead received on the
             * 1st and converted on the 20th appears in both — which is what
             * makes the ratio a conversion rate rather than two unrelated
             * counts divided by each other.
             */
            'funnel' => [
                'received' => Order::whereBetween('created_at', [$start, $end])->count(),
                'contacted' => Order::whereBetween('created_at', [$start, $end])
                    ->whereIn('status', ['contacted', 'converted'])->count(),
                'converted' => Order::whereBetween('created_at', [$start, $end])
                    ->where('status', 'converted')->count(),
                'paid' => Order::whereBetween('orders.created_at', [$start, $end])
                    ->whereHas('reservation', fn ($q) => $q->where('payment_status', 'paid'))
                    ->count(),
            ],

            'daily_orders' => $this->dailySeries(
                Order::whereBetween('created_at', [$start, $end]),
                'created_at', $start, $end
            ),

            'top_routes' => Order::whereBetween('created_at', [$start, $end])
                ->selectRaw('origin, destination, COUNT(*) as count')
                ->groupBy('origin', 'destination')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(fn ($r) => [
                    'route' => $r->origin . ' → ' . $r->destination,
                    'count' => (int) $r->count,
                ]),

            'vehicle_demand' => $this->vehicleDemand($start, $end),
        ]);
    }

    public function fleet(Request $request)
    {
        [$start, $end] = $this->window($request);

        /*
         * The one PHP-side join in this controller, and it is bounded: the
         * fleet is tens of vehicles, not thousands, so pulling them and
         * matching against a grouped trip count is cheaper and far more legible
         * than a correlated subquery per bus.
         */
        $trips = DB::table('reservation_buses')
            ->join('reservations', 'reservations.id', '=', 'reservation_buses.reservation_id')
            ->whereNull('reservations.deleted_at')
            ->whereBetween('reservations.trip_date', [$start, $end])
            ->selectRaw('reservation_buses.bus_id, COUNT(*) as trips, SUM(reservations.price_total) as revenue')
            ->groupBy('reservation_buses.bus_id')
            ->get()
            ->keyBy('bus_id');

        $buses = Bus::query()
            ->select(['id', 'plate', 'type', 'status', 'capacity', 'insurance_valid_until', 'last_service_date'])
            ->orderBy('plate')
            ->get()
            ->map(fn (Bus $bus) => [
                'id' => (string) $bus->id,
                'plate' => $bus->plate,
                'type' => $bus->type,
                'status' => $bus->status,
                'capacity' => (int) $bus->capacity,
                'trips' => (int) ($trips[$bus->id]->trips ?? 0),
                'revenue' => (int) round((float) ($trips[$bus->id]->revenue ?? 0)),
                'insurance_valid_until' => $bus->insurance_valid_until?->toDateString(),
            ]);

        return response()->json([
            'by_status' => $this->countBy(Bus::query(), 'status'),
            'by_type' => $this->countBy(Bus::query(), 'type'),
            'buses' => $buses,

            // Idle vehicles are the actionable number on this tab: a bus marked
            // active that took no trip in the window is capital doing nothing.
            'idle_count' => $buses->where('status', 'active')->where('trips', 0)->count(),
            'active_count' => $buses->where('status', 'active')->count(),

            /*
             * Compliance. `expired` and `expiring` are separated because they
             * are different actions — one grounds a vehicle today, the other
             * is a renewal to book. A single "problem" count merges an
             * emergency with a reminder.
             */
            'insurance' => [
                'expired' => Bus::whereNotNull('insurance_valid_until')
                    ->whereDate('insurance_valid_until', '<', now())->count(),
                'expiring_30d' => Bus::whereNotNull('insurance_valid_until')
                    ->whereBetween('insurance_valid_until', [now(), now()->addDays(30)])->count(),
                'missing' => Bus::whereNull('insurance_valid_until')->count(),
            ],
        ]);
    }

    public function pass(Request $request)
    {
        [$start, $end] = $this->window($request);

        $active = PassSubscription::where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '>=', now())
            ->count();

        return response()->json([
            'kpis' => [
                'active' => $active,
                'sold' => PassSubscription::whereBetween('created_at', [$start, $end])->count(),
                'revenue' => (int) PassSubscription::whereBetween('created_at', [$start, $end])
                    ->where('status', '!=', SubscriptionStatus::Pending->value)
                    ->sum('price_paid'),
                'expiring_7d' => PassSubscription::where('status', SubscriptionStatus::Active->value)
                    ->whereBetween('expires_at', [now(), now()->addDays(7)])
                    ->count(),
            ],

            'by_status' => $this->countBy(PassSubscription::query(), 'status'),

            'by_plan' => PassSubscription::query()
                ->join('pass_plans', 'pass_plans.id', '=', 'pass_subscriptions.pass_plan_id')
                ->where('pass_subscriptions.status', SubscriptionStatus::Active->value)
                ->selectRaw('pass_plans.name, COUNT(*) as count, SUM(pass_subscriptions.price_paid) as revenue')
                ->groupBy('pass_plans.name')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($r) => [
                    'plan' => $r->name,
                    'count' => (int) $r->count,
                    'revenue' => (int) $r->revenue,
                ]),

            'daily_sales' => $this->dailySeries(
                PassSubscription::whereBetween('created_at', [$start, $end]),
                'created_at', $start, $end
            ),

            /*
             * Boardings by verdict. `accepted` alone is a vanity metric — the
             * refusals are what say whether the blacklist is syncing and
             * whether people are travelling on expired passes.
             */
            'scans_by_verdict' => PassScan::whereBetween('scanned_at', [$start, $end])
                ->selectRaw('verdict, COUNT(*) as count')
                ->groupBy('verdict')
                ->get()
                ->map(fn ($r) => ['verdict' => $r->verdict, 'count' => (int) $r->count]),

            'cards' => $this->countBy(\App\Models\PassCard::query(), 'status'),
        ]);
    }

    public function clients(Request $request)
    {
        [$start, $end] = $this->window($request);

        return response()->json([
            'kpis' => [
                'total' => Client::count(),
                'new' => Client::whereBetween('created_at', [$start, $end])->count(),
                'blocked' => Client::whereNotNull('blocked_at')->count(),
                'active' => Client::whereNotNull('last_login_at')
                    ->where('last_login_at', '>=', now()->subDays(30))
                    ->count(),
            ],

            'daily_signups' => $this->dailySeries(
                Client::whereBetween('created_at', [$start, $end]),
                'created_at', $start, $end
            ),

            /*
             * Repeat rate, over ALL TIME rather than the window.
             *
             * "How many of our customers come back" is not a question about the
             * last thirty days: a client who booked in March and again in
             * August is a repeat customer, and windowing it would report a
             * loyal base as one-time buyers.
             */
            'repeat' => $this->repeatRate(),

            'top_clients' => Client::query()
                ->withCount('orders')
                ->orderByDesc('orders_count')
                ->limit(10)
                ->get(['id', 'name', 'phone'])
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'orders' => (int) $c->orders_count,
                ]),

            // The outstanding Mova Credit liability, which is a real number on
            // the balance sheet rather than a product metric.
            'wallet' => [
                'accounts' => WalletAccount::where('balance', '>', 0)->count(),
                'liability' => (int) WalletAccount::sum('balance'),
            ],
        ]);
    }

    /* ─────────────────────────── Internals ─────────────────────────── */

    /**
     * The reporting window, and the equal-length one before it.
     *
     * Shared by every tab, which is the point: `DashboardController` computed
     * its card window as `subDays($days)` and its chart window as
     * `subDays($days - 1)`, so the cards covered one more day than the chart
     * printed beside them and the two never quite agreed.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function window(Request $request): array
    {
        $request->validate([
            'range' => ['nullable', 'in:7d,30d,90d,custom'],
            'from' => ['nullable', 'date', 'required_if:range,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:range,custom'],
        ]);

        if ($request->input('range') === 'custom') {
            $start = CarbonImmutable::parse($request->input('from'))->startOfDay();
            $end = CarbonImmutable::parse($request->input('to'))->endOfDay();
        } else {
            $days = (int) rtrim($request->input('range', '30d'), 'd');
            $end = CarbonImmutable::now()->endOfDay();
            // `$days - 1` so "7 derniers jours" is seven days INCLUDING today,
            // which is what the label promises.
            $start = $end->subDays($days - 1)->startOfDay();
        }

        $length = $start->diffInDays($end) + 1;
        $prevEnd = $start->subSecond();
        $prevStart = $prevEnd->subDays($length)->startOfDay();

        return [$start, $end, $prevStart, $prevEnd];
    }

    /** @return array<string, mixed> */
    private function kpi(string $label, int|float $value, int|float $previous, string $format): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'format' => $format,
            'delta' => $this->pctDelta($value, $previous),
            'delta_unit' => 'percent',
            'previous' => $previous,
        ];
    }

    private function pctDelta(int|float $current, int|float $previous): float
    {
        if ((float) $previous === 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function conversionDelta(int $orders, int $converted, CarbonImmutable $prevStart, CarbonImmutable $prevEnd): float
    {
        $prevOrders = Order::whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $prevConverted = Order::whereBetween('created_at', [$prevStart, $prevEnd])
            ->where('status', 'converted')->count();

        $now = $orders > 0 ? $converted / $orders * 100 : 0;
        $then = $prevOrders > 0 ? $prevConverted / $prevOrders * 100 : 0;

        return round($now - $then, 1);
    }

    private function collectedBetween(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) Payment::succeededBetween($start, $end)->sum('amount');
    }

    /** Money still owed on confirmed work — the receivables figure. */
    private function outstanding(): int
    {
        return (int) Reservation::whereNull('deleted_at')
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->where('payment_status', '!=', 'paid')
            ->sum('price_total');
    }

    /** Collected vs booked, one point per day, gaps zero-filled. */
    private function revenueSeries(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $collected = Payment::succeededBetween($start, $end)
            ->selectRaw('DATE(paid_at) as d, SUM(amount) as total')
            ->groupBy('d')->pluck('total', 'd');

        $booked = Reservation::whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as d, SUM(price_total) as total')
            ->groupBy('d')->pluck('total', 'd');

        $out = [];
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();
            $out[] = [
                'date' => $key,
                'collected' => (int) ($collected[$key] ?? 0),
                'booked' => (int) round((float) ($booked[$key] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * A daily count over any query, zero-filled.
     *
     * Zero-filling matters more than it looks: a chart that omits empty days
     * silently compresses a quiet week into a single point and makes a flat
     * line look like growth.
     */
    private function dailySeries($query, string $column, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $query->selectRaw("DATE({$column}) as d, COUNT(*) as count")
            ->groupBy('d')->pluck('count', 'd');

        $out = [];
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();
            $out[] = ['date' => $key, 'count' => (int) ($rows[$key] ?? 0)];
        }

        return $out;
    }

    /** @return array<int, array{key: string|null, count: int}> */
    private function countBy($query, string $column): array
    {
        return $query->selectRaw("{$column} as k, COUNT(*) as count")
            ->groupBy('k')
            ->get()
            ->map(fn ($r) => ['key' => $r->k, 'count' => (int) $r->count])
            ->all();
    }

    /**
     * Demand per vehicle type, summing QUANTITIES not orders.
     *
     * An order for four Coasters is four Coasters of demand, not one — which is
     * what `DashboardController`'s original card counted, and why it was noise.
     * Every configured type, not just hiace vs coaster.
     *
     * @return array<int, array{type: string, count: int}>
     */
    private function vehicleDemand(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];

        foreach (array_keys(config('pricing.vehicles', [])) as $type) {
            $out[] = [
                'type' => $type,
                'count' => (int) Order::whereBetween('created_at', [$start, $end])
                    ->sum(DB::raw("COALESCE(JSON_EXTRACT(fleet_requirements, '$.\"{$type}\"'), 0)")),
            ];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /** @return array{one: int, repeat: int, rate: float} */
    private function repeatRate(): array
    {
        $counts = Order::selectRaw('client_id, COUNT(*) as c')
            ->whereNotNull('client_id')
            ->groupBy('client_id')
            ->pluck('c');

        $one = $counts->filter(fn ($c) => $c === 1)->count();
        $repeat = $counts->filter(fn ($c) => $c > 1)->count();
        $total = $one + $repeat;

        return [
            'one' => $one,
            'repeat' => $repeat,
            'rate' => $total > 0 ? round($repeat / $total * 100, 1) : 0.0,
        ];
    }
}
