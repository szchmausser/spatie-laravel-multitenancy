<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SubscriptionHistoryController extends Controller
{
    /**
     * Display subscription history for a specific tenant.
     *
     * Shows all recorded subscription events (created, plan changed, expired)
     * sorted by most recent first. Paginated for large histories.
     */
    public function index(Tenant $tenant): InertiaResponse
    {
        $history = SubscriptionHistory::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('landlord/subscriptions/history', [
            'tenant' => $tenant,
            'history' => $history,
        ]);
    }
}
