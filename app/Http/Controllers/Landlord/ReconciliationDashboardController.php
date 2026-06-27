<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Notifications\SystemAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'orphanedPayments' => $this->orphanedPayments(),
            'orphanedNotifications' => $this->orphanedNotifications(),
            'timeline' => $this->timeline(),
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

    /**
     * Retrieve payments that are pending, beyond the orphan threshold,
     * and have no associated match record.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function orphanedPayments(): Collection
    {
        $thresholdMinutes = (int) SystemConfig::get('reconciliation.orphan_threshold_minutes', 30);

        return Payment::where('status', PaymentStatus::Pending)
            ->where('created_at', '<', now()->subMinutes($thresholdMinutes))
            ->whereDoesntHave('paymentMatch')
            ->get(['id', 'amount_cents', 'created_at', 'transaction_id']);
    }

    /**
     * Retrieve unmatched payment notifications that are beyond the orphan threshold.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function orphanedNotifications(): Collection
    {
        $thresholdMinutes = (int) SystemConfig::get('reconciliation.orphan_threshold_minutes', 30);

        return PaymentMatch::where('match_status', 'unmatched')
            ->where('created_at', '<', now()->subMinutes($thresholdMinutes))
            ->get(['id', 'parsed_amount_cents', 'created_at'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'amount_cents' => $m->parsed_amount_cents,
                'created_at' => $m->created_at,
            ]);
    }

    /**
     * Build a consolidated timeline of recent reconciliation events.
     *
     * @return array<int, array<string, mixed>>
     */
    private function timeline(): array
    {
        $matches = PaymentMatch::latest()->take(20)->get()->map(fn ($m) => [
            'type' => 'match',
            'description' => "Match {$m->match_status}: {$m->parsed_reference}",
            'timestamp' => $m->created_at,
            'url' => $m->parsed_reference ? route('landlord.payment-notifications.index', ['reference' => $m->parsed_reference]) : null,
        ]);

        $notifications = PaymentNotification::latest()->take(20)->get()->map(fn ($n) => [
            'type' => 'notification',
            'description' => 'Notificación '.$n->parse_status.': '.($n->parsed_data['reference'] ?? 'N/A'),
            'timestamp' => $n->created_at,
            'url' => isset($n->parsed_data['reference'])
                ? route('landlord.payment-notifications.index', ['reference' => $n->parsed_data['reference']])
                : route('landlord.payment-notifications.index'),
        ]);

        $verifications = Payment::whereNotNull('verified_at')
            ->latest()
            ->take(20)
            ->get()
            ->map(fn ($p) => [
                'type' => 'verification',
                'description' => "Pago #{$p->id} verificado",
                'timestamp' => $p->verified_at,
                'url' => null,
            ]);

        return collect()
            ->merge($matches)
            ->merge($notifications)
            ->merge($verifications)
            ->sortByDesc('timestamp')
            ->take(20)
            ->values()
            ->toArray();
    }
}
