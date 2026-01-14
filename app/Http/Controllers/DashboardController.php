<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Reservation;
use App\Models\Order;
use App\Models\Transaction;

class DashboardController extends Controller
{
    public function cards(Request $req)
    {
        $range = in_array($req->get('range'), ['7d', '30d', '90d']) ? $req->get('range') : '30d';
        $days  = (int) rtrim($range, 'd');

        $end   = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($days)->startOfDay();

        $prevEnd   = (clone $start)->subSecond();
        $prevStart = (clone $prevEnd)->subDays($days);

        // --- 1. CASH FLOW (Actual Money vs Booked) ---
        // Collected: Sum of completed transactions in window
        $collected = Transaction::where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $prevCollected = Transaction::where('status', 'completed')
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->sum('amount');

        // Booked: Sum of price_total of CONFIRMED reservations in window
        $booked = Reservation::whereNull('deleted_at')
            ->where('status', 'confirmed')
            ->whereBetween('created_at', [$start, $end])
            ->sum('price_total');

        // --- 2. PIPELINE (Orders -> Reservations) ---
        $newLeads = Order::whereBetween('created_at', [$start, $end])->count();
        $prevLeads = Order::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $converted = Order::whereBetween('created_at', [$start, $end])
            ->where('status', 'converted')
            ->count();

        // Conversion Rate
        $convRate = $newLeads > 0 ? ($converted / $newLeads) * 100 : 0;
        $prevConverted = Order::whereBetween('created_at', [$prevStart, $prevEnd])->where('status', 'converted')->count();
        $prevConvRate = $prevLeads > 0 ? ($prevConverted / $prevLeads) * 100 : 0;

        // --- 3. FLEET DEMAND (Based on Orders JSON) ---
        // We look at raw demand from Orders to see what clients want most
        // This is a rough aggregation of the JSON keys
        $hiaceDemand = Order::whereBetween('created_at', [$start, $end])
            ->whereJsonContains('fleet_requirements', ['hiace' => 1]) // Simplified check, usually requires raw SQL for accurate sum
            ->orWhereRaw("JSON_EXTRACT(fleet_requirements, '$.hiace') > 0")
            ->count();

        $coasterDemand = Order::whereBetween('created_at', [$start, $end])
            ->orWhereRaw("JSON_EXTRACT(fleet_requirements, '$.coaster') > 0")
            ->count();

        $topVehicle = $coasterDemand > $hiaceDemand ? 'Coaster' : 'Hiace';

        return response()->json([
            'range' => $range,
            'cards' => [
                'revenue' => [
                    'label' => 'Trésorerie Encaissée',
                    'value' => $collected,
                    'format' => 'money',
                    'delta_pct' => $this->pctDelta($collected, $prevCollected),
                    'subtext' => "Sur " . number_format($booked, 0) . " FCFA facturés"
                ],
                'leads' => [
                    'label' => 'Nouveaux Leads',
                    'value' => $newLeads,
                    'format' => 'number',
                    'delta_pct' => $this->pctDelta($newLeads, $prevLeads),
                    'subtext' => "Demandes reçues"
                ],
                'conversion' => [
                    'label' => 'Taux de Conversion',
                    'value' => $convRate,
                    'format' => 'percent',
                    'delta_pct' => ($convRate - $prevConvRate), // Absolute diff for rates
                    'subtext' => "Commandes converties"
                ],
                'demand' => [
                    'label' => 'Véhicule Tendance',
                    'value' => $topVehicle,
                    'format' => 'text',
                    'delta_pct' => 0, // N/A
                    'subtext' => $coasterDemand + $hiaceDemand . " demandes totales"
                ]
            ]
        ]);
    }

    public function charts(Request $req)
    {
        $range = in_array($req->get('range'), ['7d', '30d', '90d']) ? $req->get('range') : '30d';
        $days  = (int) rtrim($range, 'd');

        $end   = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        // 1. Daily Revenue (Booked vs Collected)
        // Group Transactions by Day
        $collections = Transaction::selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->get()
            ->pluck('total', 'date');

        // Group Reservations (Booked Revenue) by Day
        $bookings = Reservation::selectRaw('DATE(created_at) as date, SUM(price_total) as total')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->get()
            ->pluck('total', 'date');

        $data = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $dateKey = $cursor->toDateString();
            $data[] = [
                'date' => $dateKey,
                'booked' => (float) ($bookings[$dateKey] ?? 0),
                'collected' => (float) ($collections[$dateKey] ?? 0),
            ];
            $cursor->addDay();
        }

        return response()->json([
            'range' => $range,
            'chart_data' => $data
        ]);
    }

    private function pctDelta($curr, $prev): float
    {
        $c = (float) $curr;
        $p = (float) $prev;
        if ($p == 0.0) return $c > 0 ? 100.0 : 0.0;
        return round((($c - $p) / $p) * 100, 1);
    }
}
