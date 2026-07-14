<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Response;

class SalesDashboardController extends Controller
{
    /**
     * Display the consolidated sales dashboard with KPIs and breakdowns.
     */
    public function index(Request $request): Response
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $request->filled('from') ? $request->from : null;
        $to = $request->filled('to') ? $request->to : null;

        $kpis = $this->computeKpis($from, $to);
        $changes = $this->periodChange($from, $to);

        return inertia('admin/sales/index', [
            'kpis' => [
                'totalRevenue' => $kpis['totalRevenue'],
                'paidOrders' => $kpis['paidOrders'],
                'averageOrderValue' => $kpis['averageOrderValue'],
                'canceledAmount' => $kpis['canceledAmount'],
                'totalOrders' => $kpis['totalOrders'],
                'changes' => $changes,
            ],
            'revenueByMethod' => $this->revenueByPaymentMethod($from, $to),
            'revenueByType' => $this->revenueByType($from, $to),
            'topPlans' => $this->topPlans($from, $to),
            'topResources' => $this->topResources($from, $to),
            'monthlyEvolution' => $this->monthlyEvolution($from, $to),
            'recentOrders' => $this->recentOrders($from, $to),
            'revenueVsCancellations' => $this->revenueVsCancellations($from, $to),
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * Build the base query scope for a date range.
     */
    private function scopeDateRange($query, ?string $from, ?string $to, string $column = 'created_at'): void
    {
        if ($from !== null) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate($column, '<=', $to);
        }
    }

    /**
     * Compute all KPI values for a given date range.
     *
     * @return array{totalRevenue: int, paidOrders: int, averageOrderValue: int, canceledAmount: int, totalOrders: int}
     */
    private function computeKpis(?string $from, ?string $to): array
    {
        $totalRevenue = $this->totalRevenue($from, $to);
        $paidOrders = $this->paidOrdersCount($from, $to);
        $canceledAmount = $this->canceledAmount($from, $to);
        $totalOrders = $this->totalOrders($from, $to);

        return [
            'totalRevenue' => $totalRevenue,
            'paidOrders' => $paidOrders,
            'averageOrderValue' => $paidOrders > 0 ? (int) round($totalRevenue / $paidOrders) : 0,
            'canceledAmount' => $canceledAmount,
            'totalOrders' => $totalOrders,
        ];
    }

    /**
     * Sum of verified payment amounts in cents.
     */
    private function totalRevenue(?string $from, ?string $to): int
    {
        $query = Payment::where('status', PaymentStatus::Verified);
        $this->scopeDateRange($query, $from, $to);

        return (int) $query->sum('amount_cents');
    }

    /**
     * Count orders with at least one verified payment.
     */
    private function paidOrdersCount(?string $from, ?string $to): int
    {
        $query = Order::whereHas('payments', fn ($q) => $q->where('status', PaymentStatus::Verified));
        $this->scopeDateRange($query, $from, $to);

        return $query->count();
    }

    /**
     * Sum of cancelled payment amounts in cents.
     */
    private function canceledAmount(?string $from, ?string $to): int
    {
        $query = Payment::where('status', PaymentStatus::Cancelled);
        $this->scopeDateRange($query, $from, $to);

        return (int) $query->sum('amount_cents');
    }

    /**
     * Count all orders regardless of status.
     */
    private function totalOrders(?string $from, ?string $to): int
    {
        $query = Order::query();
        $this->scopeDateRange($query, $from, $to);

        return $query->count();
    }

    /**
     * Revenue grouped by payment method (pago_movil vs bank_transfer).
     *
     * @return array<int, array{method: string, amount_cents: int, percentage: float}>
     */
    private function revenueByPaymentMethod(?string $from, ?string $to): array
    {
        $query = Payment::selectRaw('payment_method, SUM(amount_cents) as total')
            ->where('status', PaymentStatus::Verified);
        $this->scopeDateRange($query, $from, $to);

        $rows = $query->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $grandTotal = $rows->sum();

        return $rows->map(fn ($total, $method) => [
            'method' => $method,
            'amount_cents' => (int) $total,
            'percentage' => $grandTotal > 0 ? round(($total / $grandTotal) * 100, 1) : 0,
        ])->values()->toArray();
    }

    /**
     * Revenue grouped by order type (plan vs resource).
     *
     * @return array<int, array{type: string, amount_cents: int, percentage: float}>
     */
    private function revenueByType(?string $from, ?string $to): array
    {
        $query = Payment::selectRaw('orders.plan_id, orders.resource_id, SUM(payments.amount_cents) as total')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', PaymentStatus::Verified);
        $this->scopeDateRange($query, $from, $to, 'payments.created_at');

        $rows = $query->groupBy('orders.plan_id', 'orders.resource_id')
            ->get();

        $planTotal = $rows->where('plan_id', '!==', null)->sum('total');
        $resourceTotal = $rows->where('resource_id', '!==', null)->sum('total');
        $grandTotal = $planTotal + $resourceTotal;

        return array_values(array_filter([
            $planTotal > 0 || $grandTotal > 0 ? [
                'type' => 'plan',
                'amount_cents' => (int) $planTotal,
                'percentage' => $grandTotal > 0 ? round(($planTotal / $grandTotal) * 100, 1) : 0,
            ] : null,
            $resourceTotal > 0 || $grandTotal > 0 ? [
                'type' => 'resource',
                'amount_cents' => (int) $resourceTotal,
                'percentage' => $grandTotal > 0 ? round(($resourceTotal / $grandTotal) * 100, 1) : 0,
            ] : null,
        ]));
    }

    /**
     * Top plans ranked by paid order count (descending).
     *
     * @return array<int, array{plan: array{id: int, name: string}, order_count: int, revenue_cents: int}>
     */
    private function topPlans(?string $from, ?string $to): array
    {
        $query = Order::selectRaw('plans.id, plans.name, COUNT(*) as order_count, COALESCE(SUM(payments.amount_cents), 0) as revenue_cents')
            ->join('plans', 'plans.id', '=', 'orders.plan_id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('payments.status', PaymentStatus::Verified)
            ->whereNotNull('orders.plan_id')
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('order_count');
        $this->scopeDateRange($query, $from, $to, 'orders.created_at');

        return $query->get()->map(fn ($row) => [
            'plan' => ['id' => (int) $row->id, 'name' => $row->name],
            'order_count' => (int) $row->order_count,
            'revenue_cents' => (int) $row->revenue_cents,
        ])->toArray();
    }

    /**
     * Top resources ranked by paid order count (descending).
     *
     * @return array<int, array{resource: array{id: int, name: string}, order_count: int, revenue_cents: int}>
     */
    private function topResources(?string $from, ?string $to): array
    {
        $query = Order::selectRaw('resources.id, resources.name, COUNT(*) as order_count, COALESCE(SUM(payments.amount_cents), 0) as revenue_cents')
            ->join('resources', 'resources.id', '=', 'orders.resource_id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('payments.status', PaymentStatus::Verified)
            ->whereNotNull('orders.resource_id')
            ->groupBy('resources.id', 'resources.name')
            ->orderByDesc('order_count');
        $this->scopeDateRange($query, $from, $to, 'orders.created_at');

        return $query->get()->map(fn ($row) => [
            'resource' => ['id' => (int) $row->id, 'name' => $row->name],
            'order_count' => (int) $row->order_count,
            'revenue_cents' => (int) $row->revenue_cents,
        ])->toArray();
    }

    /**
     * Monthly verified revenue grouped by YYYY-MM.
     *
     * @return array<int, array{month: string, revenue_cents: int}>
     */
    private function monthlyEvolution(?string $from, ?string $to): array
    {
        $query = Payment::selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, SUM(amount_cents) as total")
            ->where('status', PaymentStatus::Verified)
            ->groupBy('month')
            ->orderBy('month');
        $this->scopeDateRange($query, $from, $to);

        return $query->get()->map(fn ($row) => [
            'month' => $row->month,
            'revenue_cents' => (int) $row->total,
        ])->toArray();
    }

    /**
     * Last 10 orders with tenant and buyable info.
     *
     * @return array<int, array>
     */
    private function recentOrders(?string $from, ?string $to): array
    {
        $query = Order::with(['tenant', 'plan', 'resource'])
            ->orderByDesc('created_at')
            ->limit(10);
        $this->scopeDateRange($query, $from, $to);

        return $query->get()->map(fn (Order $order) => [
            'id' => $order->id,
            'total_cents' => $order->total_cents,
            'status' => $order->status->value,
            'created_at' => $order->created_at->toIso8601String(),
            'tenant' => [
                'id' => $order->tenant->id,
                'name' => $order->tenant->name,
            ],
            'buyable' => $order->buyable ? ['name' => $order->buyable->name] : null,
            'buyable_type' => $order->buyable_type,
        ])->toArray();
    }

    /**
     * Side-by-side total verified revenue vs total cancelled amount.
     *
     * @return array{revenue_cents: int, canceled_cents: int}
     */
    private function revenueVsCancellations(?string $from, ?string $to): array
    {
        $verifiedQuery = Payment::where('status', PaymentStatus::Verified);
        $this->scopeDateRange($verifiedQuery, $from, $to);

        $cancelledQuery = Payment::where('status', PaymentStatus::Cancelled);
        $this->scopeDateRange($cancelledQuery, $from, $to);

        return [
            'revenue_cents' => (int) $verifiedQuery->sum('amount_cents'),
            'canceled_cents' => (int) $cancelledQuery->sum('amount_cents'),
        ];
    }

    /**
     * Compute period-over-period % changes for KPIs.
     *
     * When both from and to are present, computes the prior period of equal
     * length and re-runs KPI queries for that prior period.
     *
     * @return array{totalRevenue: int, paidOrders: int, averageOrderValue: int, canceledAmount: int, totalOrders: int}
     */
    private function periodChange(?string $from, ?string $to): array
    {
        $default = [
            'totalRevenue' => 0,
            'paidOrders' => 0,
            'averageOrderValue' => 0,
            'canceledAmount' => 0,
            'totalOrders' => 0,
        ];

        if ($from === null || $to === null) {
            return $default;
        }

        $fromDate = new \DateTime($from);
        $toDate = new \DateTime($to);
        $interval = $fromDate->diff($toDate);
        $priorTo = clone $fromDate;
        $priorTo->modify('-1 day');
        $priorFrom = clone $priorTo;
        $priorFrom->modify("-{$interval->days} days");

        $current = $this->computeKpis($from, $to);
        $prior = $this->computeKpis(
            $priorFrom->format('Y-m-d'),
            $priorTo->format('Y-m-d'),
        );

        return [
            'totalRevenue' => $prior['totalRevenue'] > 0
                ? (int) round((($current['totalRevenue'] - $prior['totalRevenue']) / $prior['totalRevenue']) * 100)
                : ($current['totalRevenue'] > 0 ? 100 : 0),
            'paidOrders' => $prior['paidOrders'] > 0
                ? (int) round((($current['paidOrders'] - $prior['paidOrders']) / $prior['paidOrders']) * 100)
                : ($current['paidOrders'] > 0 ? 100 : 0),
            'averageOrderValue' => $prior['averageOrderValue'] > 0
                ? (int) round((($current['averageOrderValue'] - $prior['averageOrderValue']) / $prior['averageOrderValue']) * 100)
                : ($current['averageOrderValue'] > 0 ? 100 : 0),
            'canceledAmount' => $prior['canceledAmount'] > 0
                ? (int) round((($current['canceledAmount'] - $prior['canceledAmount']) / $prior['canceledAmount']) * 100)
                : ($current['canceledAmount'] > 0 ? 100 : 0),
            'totalOrders' => $prior['totalOrders'] > 0
                ? (int) round((($current['totalOrders'] - $prior['totalOrders']) / $prior['totalOrders']) * 100)
                : ($current['totalOrders'] > 0 ? 100 : 0),
        ];
    }
}
