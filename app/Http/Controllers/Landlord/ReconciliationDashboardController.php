<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Models\Tenant;
use App\Notifications\SystemAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Response;

class ReconciliationDashboardController extends Controller
{
    /**
     * Display the reconciliation dashboard with KPIs and status data.
     */
    public function index(): Response
    {
        return inertia('landlord/reconciliation/index', [
            'matchRate' => $this->matchRate(),
            'autoverifiedToday' => $this->autoverifiedToday(),
            'activeAlerts' => $this->activeAlerts(),
            'failedNotifications' => $this->failedNotifications(),
            'shadowModeEnabled' => $this->shadowModeStatus(),
            'pollingInterval' => $this->pollingInterval(),
        ]);
    }

    /**
     * Toggle the reconciliation shadow mode on or off.
     */
    public function toggleShadowMode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        SystemConfig::set('reconciliation.shadow_mode_enabled', $validated['enabled']);

        return redirect()->back()->with(
            'success',
            'Shadow mode '.($validated['enabled'] ? 'activado' : 'desactivado').' correctamente.'
        );
    }

    /**
     * List pending (unmatched) payments with search, date, and tenant filters.
     */
    public function pending(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);

        $query = Payment::with(['tenant', 'order.plan', 'order.resource'])
            ->where('status', PaymentStatus::Pending)
            ->whereDoesntHave('paymentMatch');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('tenant', fn ($t) => $t->where('name', 'ilike', "%{$search}%"))
                    ->orWhere('transaction_id', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        $payments = $query->orderBy('id', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Also fetch unmatched PaymentMatch records (bank notifications that
        // arrived without a matching customer-reported payment yet)
        $unmatchedReferences = PaymentMatch::with('notification')
            ->whereNull('payment_id')
            ->where('match_status', 'unmatched')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn (PaymentMatch $match): array => [
                'id' => $match->id,
                'reference' => $match->parsed_reference,
                'amount_cents' => $match->parsed_amount_cents,
                'sender_phone_last4' => $match->parsed_sender_phone_last4,
                'bank_code' => $match->notification?->bank_code ?? 'N/A',
                'created_at' => $match->created_at->toIso8601String(),
            ]);

        return inertia('landlord/reconciliation/pending', [
            'payments' => $payments,
            'unmatched_references' => $unmatchedReferences,
            'filters' => $request->only(['search', 'from', 'to', 'tenant_id']),
            'tenants' => Tenant::orderBy('name')->pluck('name', 'id'),
            'pollingInterval' => $this->pollingInterval(),
        ]);
    }

    /**
     * List matched payments with match_status filter and auto/manual match type.
     */
    public function matched(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'match_status' => ['nullable', 'string', 'in:matched,unmatched,pending,duplicate_attempt'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Payment::with(['tenant', 'order.plan', 'order.resource', 'paymentMatch.notification', 'verifier'])
            ->whereHas('paymentMatch', fn ($q) => $q->when(
                $request->filled('match_status'),
                fn ($s) => $s->where('match_status', $request->match_status)
            ));

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('tenant', fn ($t) => $t->where('name', 'ilike', "%{$search}%"))
                    ->orWhere('transaction_id', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $payments = $query->orderBy('id', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Append match_type to each payment
        $payments->getCollection()->transform(function ($payment) {
            $payment->match_type = $payment->verified_by === null ? 'auto' : 'manual';

            return $payment;
        });

        return inertia('landlord/reconciliation/matched', [
            'payments' => $payments,
            'filters' => $request->only(['search', 'match_status', 'from', 'to']),
            'pollingInterval' => $this->pollingInterval(),
        ]);
    }

    /**
     * Show a single payment with all relations for the detail panel.
     */
    public function show(Payment $payment): Response
    {
        $payment->load(['tenant', 'order', 'paymentMatch.notification', 'verifier', 'pagoMovilDetail', 'bankTransferDetail']);

        $payment->match_type = $payment->verified_by === null ? 'auto' : 'manual';

        return inertia('landlord/reconciliation/show', [
            'payment' => $payment,
        ]);
    }

    /**
     * Aggregated statistics across all payments with optional filters.
     */
    public function stats(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);

        $query = Payment::query();

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        $byStatus = (clone $query)
            ->selectRaw('status::text, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Total aggregate
        $totalAggregate = (clone $query)
            ->selectRaw('COUNT(*) as total_payments, COALESCE(SUM(amount_cents), 0) as total_amount_cents')
            ->first();

        // By bank: payments that have a match with a notification
        $byBankQuery = Payment::query()
            ->selectRaw('pn.bank_code, COUNT(*) as count, COALESCE(SUM(payments.amount_cents), 0) as total_cents')
            ->join('payment_matches as pm', 'pm.payment_id', '=', 'payments.id')
            ->join('payment_notifications as pn', 'pn.id', '=', 'pm.payment_notification_id')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('payments.created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('payments.created_at', '<=', $request->to))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('payments.tenant_id', $request->tenant_id))
            ->when($request->filled('bank_code'), fn ($q) => $q->where('pn.bank_code', $request->bank_code))
            ->groupBy('pn.bank_code')
            ->orderBy('pn.bank_code');

        $byBank = $byBankQuery->get();

        $stats = [
            'total_payments' => (int) ($totalAggregate->total_payments ?? 0),
            'total_amount_cents' => (int) ($totalAggregate->total_amount_cents ?? 0),
            'by_status' => $byStatus->map(fn ($count) => (int) $count)->toArray(),
            'by_bank' => $byBank->toArray(),
            'from' => $request->from,
            'to' => $request->to,
        ];

        return inertia('landlord/reconciliation/stats', [
            'stats' => $stats,
            'filters' => $request->only(['from', 'to', 'bank_code', 'tenant_id']),
            'tenants' => Tenant::orderBy('name')->pluck('name', 'id'),
            'pollingInterval' => $this->pollingInterval(),
        ]);
    }

    /**
     * Get the configured polling interval in seconds (0 = disabled).
     */
    private function pollingInterval(): int
    {
        return (int) SystemConfig::get('reconciliation.polling_interval_seconds', 30);
    }

    /**
     * Calculate match rate statistics across all payment matches.
     */
    private function matchRate(): array
    {
        $counts = PaymentMatch::selectRaw('match_status, COUNT(*) as count')
            ->groupBy('match_status')
            ->pluck('count', 'match_status');

        $total = $counts->sum();
        $matched = $counts->get('matched', 0);

        return [
            'percentage' => $total > 0 ? round(($matched / $total) * 100, 1) : 0,
            'total' => $total,
            'matched' => $matched,
            'by_status' => [
                'matched' => $counts->get('matched', 0),
                'unmatched' => $counts->get('unmatched', 0),
                'pending' => $counts->get('pending', 0),
                'duplicate' => $counts->get('duplicate_attempt', 0),
            ],
        ];
    }

    /**
     * Count payments auto-verified by the system today.
     */
    private function autoverifiedToday(): int
    {
        return Payment::whereDate('verified_at', today())
            ->whereNull('verified_by')
            ->count();
    }

    /**
     * Count unread system alerts for the authenticated admin.
     */
    private function activeAlerts(): int
    {
        return Auth::user()->notifications()
            ->where('type', SystemAlert::class)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Count payment notifications that failed to parse.
     */
    private function failedNotifications(): int
    {
        return PaymentNotification::failed()->count();
    }

    /**
     * Check whether shadow mode is currently enabled.
     */
    private function shadowModeStatus(): bool
    {
        return SystemConfig::get('reconciliation.shadow_mode_enabled', false);
    }
}
